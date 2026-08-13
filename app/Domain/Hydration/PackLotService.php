<?php

namespace App\Domain\Hydration;

use App\Core\AppException;
use App\Core\Config;
use App\Core\Logger;

/**
 * 封包批號取號 —— 商業邏輯。
 *
 * 機台 API（public/service/v1/packet-lot.php）進來時跑這一段：
 *
 *   1. 鎖住那個乾片批號「最新一次水化」的那一列
 *   2. 已經有封包批號 => 原號回傳（不再燒號）
 *   3. 抓當天已發出去的最大號，往前推一步算出新號
 *   4. 寫回 PACKET_LOT_TEMP_AUTO，順便記 UPDATE_USER / UPDATE_TIME
 *   5. COMMIT，回傳號碼
 *
 * 撞到唯一鍵（別人剛好也算出同一個號）就整個重來一次，最多五次。
 *
 * 四個必須這樣做的理由：
 *
 * 【可以重複呼叫】第 2 步讓這支 API 變成 idempotent。
 *   機台逾時重送、網路斷線重試都會拿到同一個號碼。
 *   少了這一步，重試一次就多一個號，當天的號碼與實際封包數就對不起來。
 *
 * 【號碼從資料算，不另外記帳】第 3 步抓的是資料表裡當天最大的號。
 *   有人手動補號、修資料、清掉幾列，下一號永遠算得對。
 *   計數表會跟真實資料對不起來，而且對不起來的時候它照樣發號。
 *
 * 【撞號由資料庫擋】兩支同時取號會算出同一個號，
 *   UX_AQUA_SCHEDULE_PACKET 唯一鍵會擋下其中一支，這裡收到之後重算重試。
 *   一天最多 120 個號，撞在一起的機率極低。
 *
 * 【交易要短】鎖持有到 COMMIT。這個流程裡沒有任何檔案處理或外部呼叫，
 *   就是為了讓鎖的時間短到只有幾毫秒。
 */
class PackLotService
{
    /** 撞到唯一鍵時最多重來幾次 */
    const MAX_RETRY = 5;

    /** @var PackLotRepository */
    private $repo;

    public function __construct(?PackLotRepository $repo = null)
    {
        $this->repo = $repo ?: new PackLotRepository();
    }

    /**
     * 取一個封包批號，並寫回對應的水化排程列。
     *
     * @param string $ppcupLot   乾片批號
     * @param string $updateUser 寫進 UPDATE_USER 的名字。
     *                           機台 API 傳機台名稱、頁面上傳時傳登入者姓名。
     *
     * @return array{ppcup_lot:string, packet_lot_temp_auto:string, aqua_cycle_num:int,
     *               aqua_schedule_date_code:string, reused:bool}
     */
    public function allocate(string $ppcupLot, string $updateUser): array
    {
        $ppcupLot   = strtoupper(trim($ppcupLot));
        $updateUser = trim($updateUser);

        if ($ppcupLot === '') {
            throw new AppException('乾片批號（ppcup_lot）不可為空白。', 422);
        }

        if ($updateUser === '') {
            // UPDATE_USER 是 NOT NULL，而且「這個號是誰要走的」本來就該留下來
            throw new AppException('缺少 update_user（機台名稱或操作人員）。', 422);
        }

        $updateUser = mb_substr($updateUser, 0, 100);

        for ($attempt = 1; $attempt <= self::MAX_RETRY; $attempt++) {
            try {
                return $this->repo->conn()->transaction(function () use ($ppcupLot, $updateUser) {
                    return $this->allocateInTransaction($ppcupLot, $updateUser);
                });
            } catch (AppException $e) {
                throw $e;
            } catch (\Throwable $e) {
                // 別人剛好也算出同一個號：整個重來（重新抓最大號）
                if (PackLotRepository::isDuplicate($e) && $attempt < self::MAX_RETRY) {
                    Logger::info('封包批號撞號，重新取號', [
                        'ppcup_lot' => $ppcupLot,
                        'attempt'   => $attempt,
                    ]);
                    continue;
                }

                // ORA-00054 / ORA-30006：等鎖等到逾時。這不是壞掉，是同時進來的人太多，
                // 告訴機台稍後重試就好，不要回 500 讓對方以為資料寫壞了。
                if (preg_match('/ORA-0*(54|30006)/', $e->getMessage())) {
                    Logger::warning('取號等鎖逾時', ['ppcup_lot' => $ppcupLot]);

                    throw new AppException('系統忙碌中，請三秒後重試。', 503);
                }

                throw $e;
            }
        }

        Logger::error('封包批號重試多次仍撞號', ['ppcup_lot' => $ppcupLot]);

        throw new AppException('系統忙碌中，請三秒後重試。', 503);
    }

    /**
     * 交易內的流程。這裡面每一句都不能是慢動作。
     */
    private function allocateInTransaction(string $ppcupLot, string $updateUser): array
    {
        // --- 1. 鎖住最新一次水化那一列 ---
        $row = $this->repo->lockLatestRow($ppcupLot);

        if ($row === null) {
            throw new AppException(
                '找不到乾片批號 ' . $ppcupLot . ' 的水化紀錄，請先在水化排程頁匯入資料。',
                404
            );
        }

        // --- 2. 已經取過號就原號回傳 ---
        if (!empty($row['packet_lot_temp_auto'])) {
            return $this->result($row, true);
        }

        $cycleNum = (int) $row['aqua_cycle_num'];
        $dateCode = strtoupper(trim((string) $row['aqua_schedule_date_code']));

        if ($dateCode === '') {
            throw new AppException(
                '乾片批號 ' . $ppcupLot . ' 的水化日編號是空的，無法產生封包批號。',
                422
            );
        }

        // --- 3. 從當天已發出去的號算下一個 ---
        $lastCode = $this->repo->maxSeqCode($dateCode);
        $value    = PackLotNumber::firstOrNext($lastCode);

        if (!PackLotNumber::fits($value)) {
            throw new AppException(
                '水化日 ' . $dateCode . ' 的封包批號已經用完（一天最多 '
                . PackLotNumber::capacity() . ' 組），請聯絡資訊人員。',
                409
            );
        }

        $packetLot = PackLotNumber::compose($row['ppcup_lot'], $dateCode, $value);

        // --- 4. 寫回 ---
        if ($this->repo->writeBack($ppcupLot, $cycleNum, $packetLot, $updateUser) === 0) {
            // 有人搶先寫進去了（正常情況下鎖住之後不會發生）。
            // 以資料庫裡的為準，不要硬蓋掉。
            $fresh = $this->repo->findRow($ppcupLot, $cycleNum);

            if ($fresh !== null && !empty($fresh['packet_lot_temp_auto'])) {
                Logger::warning('封包批號寫回時已存在，改回傳既有號碼', [
                    'ppcup_lot' => $ppcupLot,
                    'existing'  => $fresh['packet_lot_temp_auto'],
                    'dropped'   => $packetLot,
                ]);

                return $this->result($fresh, true);
            }

            // 示範模式（DemoConnection 不真的寫入）會走到這裡，資料庫裡仍然是空的。
            if (!Config::get('app.demo_mode')) {
                throw new AppException('封包批號寫回失敗，請重試。', 500);
            }
        }

        Logger::info('封包批號取號', [
            'ppcup_lot'               => $ppcupLot,
            'aqua_cycle_num'          => $cycleNum,
            'aqua_schedule_date_code' => $dateCode,
            'packet_lot_temp_auto'    => $packetLot,
            'update_user'             => $updateUser,
        ]);

        $row['packet_lot_temp_auto'] = $packetLot;

        return $this->result($row, false);
    }

    private function result(array $row, bool $reused): array
    {
        return [
            'ppcup_lot'               => (string) $row['ppcup_lot'],
            'aqua_cycle_num'          => (int) $row['aqua_cycle_num'],
            'aqua_schedule_date_code' => (string) $row['aqua_schedule_date_code'],
            'packet_lot_temp_auto'    => (string) $row['packet_lot_temp_auto'],

            // true = 這個號碼是之前就取好的（機台重送時會看到）
            'reused'                  => $reused,
        ];
    }
}
