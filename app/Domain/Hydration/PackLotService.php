<?php

namespace App\Domain\Hydration;

use App\Core\AppException;
use App\Core\Config;
use App\Core\Logger;

/**
 * 封包批號取號 —— 商業邏輯。
 *
 * 對外 API（public/service/v1/hyd-pack.php）進來時跑這一段：
 *
 *   1. 鎖住那個乾片批號「最新一次水化」的那一列
 *   2. 已經有預配封包批號 => 原號回傳（不再燒號）
 *   3. 鎖住當日順序、算出下一個號碼、把順序往前推
 *   4. 把號碼寫回那一列
 *   5. COMMIT，回傳號碼
 *
 * 三個必須這樣做的理由：
 *
 * 【可以重複呼叫】第 2 步讓這支 API 變成 idempotent。
 *   對方逾時重送、網路斷線重試都會拿到同一個號碼。
 *   少了這一步，重試一次就多一個號，當天的號碼與實際封包數就對不起來。
 *
 * 【同時進來不會撞號】第 3 步鎖的是「當天那一列」，
 *   所以同一天的取號會排隊、不同天完全不互相影響。
 *   用 SELECT MAX(...)+1 的話兩支同時算會得到同一個號碼。
 *
 * 【交易要短】鎖持有到 COMMIT。這個流程裡沒有任何檔案處理或外部呼叫，
 *   就是為了讓鎖的時間短到只有幾毫秒。
 */
class PackLotService
{
    /** @var PackLotRepository */
    private $repo;

    public function __construct(?PackLotRepository $repo = null)
    {
        $this->repo = $repo ?: new PackLotRepository();
    }

    /**
     * 取一個封包批號，並寫回對應的水化紀錄。
     *
     * @return array{dry_lot_no:string, pre_pack_lot_no:string, hyd_seq:int,
     *               hyd_day_code:string, reused:bool}
     */
    public function allocate(string $dryLotNo): array
    {
        $dryLotNo = strtoupper(trim($dryLotNo));

        if ($dryLotNo === '') {
            throw new AppException('乾片批號不可為空白。', 422);
        }

        try {
            return $this->repo->conn()->transaction(function () use ($dryLotNo) {
                return $this->allocateInTransaction($dryLotNo);
            });
        } catch (AppException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // ORA-00054 / ORA-30006：等鎖等到逾時。這不是壞掉，是同時進來的人太多，
            // 告訴呼叫端稍後重試就好，不要回 500 讓對方以為資料寫壞了。
            if (preg_match('/ORA-0*(54|30006)/', $e->getMessage())) {
                Logger::warning('取號等鎖逾時', ['dry_lot_no' => $dryLotNo]);

                throw new AppException('系統忙碌中，請三秒後重試。', 503);
            }

            throw $e;
        }
    }

    /**
     * 交易內的流程。這裡面每一句都不能是慢動作。
     */
    private function allocateInTransaction(string $dryLotNo): array
    {
        // --- 1. 鎖住最新一次水化那一列 ---
        $row = $this->repo->lockLatestRow($dryLotNo);

        if ($row === null) {
            throw new AppException(
                '找不到乾片批號 ' . $dryLotNo . ' 的水化紀錄，請先在水化管理頁匯入資料。',
                404
            );
        }

        // --- 2. 已經取過號就原號回傳 ---
        if (!empty($row['pre_pack_lot_no'])) {
            return $this->result($row, true);
        }

        $dayCode = strtoupper(trim((string) $row['hyd_day_code']));

        if ($dayCode === '') {
            throw new AppException(
                '乾片批號 ' . $dryLotNo . ' 的水化日編號是空的，無法產生封包批號。',
                422
            );
        }

        // --- 3. 鎖住當日順序 ---
        $value = $this->repo->lockCounter($dayCode);

        if ($value === null) {
            // 今天第一次取號。建不成表示別人剛好也在建，重新鎖一次就有了。
            if (!$this->repo->createCounter($dayCode, PackLotNumber::first())) {
                $value = $this->repo->lockCounter($dayCode);
            } else {
                $value = PackLotNumber::first();
            }
        }

        if ($value === null || !PackLotNumber::fits($value)) {
            throw new AppException(
                '水化日 ' . $dayCode . ' 的封包批號已經用完（一天最多 '
                . PackLotNumber::capacity() . ' 組），請聯絡資訊人員。',
                409
            );
        }

        $packLotNo = PackLotNumber::compose($row['dry_lot_no'], $dayCode, $value);

        // 先把順序推掉再寫號碼：就算後面寫回失敗，也只是浪費一個號，
        // 不會兩個人拿到同一個號（浪費一個號可以解釋，撞號不行）
        $this->repo->advanceCounter($dayCode, PackLotNumber::next($value));

        // --- 4. 寫回 ---
        if ($this->repo->writeBack((int) $row['hyd_id'], $packLotNo) === 0) {
            // 有人搶先寫進去了（正常情況下鎖住之後不會發生）。
            // 以資料庫裡的為準，不要硬蓋掉。
            $fresh = $this->repo->findRow((int) $row['hyd_id']);

            if ($fresh !== null && !empty($fresh['pre_pack_lot_no'])) {
                Logger::warning('封包批號寫回時已存在，改回傳既有號碼', [
                    'dry_lot_no' => $dryLotNo,
                    'existing'   => $fresh['pre_pack_lot_no'],
                    'dropped'    => $packLotNo,
                ]);

                return $this->result($fresh, true);
            }

            // 示範模式（DemoConnection 不真的寫入）會走到這裡，資料庫裡仍然是空的。
            if (!Config::get('app.demo_mode')) {
                throw new AppException('封包批號寫回失敗，請重試。', 500);
            }
        }

        Logger::info('封包批號取號', [
            'dry_lot_no'      => $dryLotNo,
            'hyd_day_code'    => $dayCode,
            'pre_pack_lot_no' => $packLotNo,
        ]);

        $row['pre_pack_lot_no'] = $packLotNo;

        return $this->result($row, false);
    }

    private function result(array $row, bool $reused): array
    {
        return [
            'dry_lot_no'      => (string) $row['dry_lot_no'],
            'hyd_seq'         => (int) $row['hyd_seq'],
            'hyd_day_code'    => (string) $row['hyd_day_code'],
            'pre_pack_lot_no' => (string) $row['pre_pack_lot_no'],
            'pack_lot_no'     => $row['pack_lot_no'] ?? null,

            // true = 這個號碼是之前就取好的（呼叫端重送時會看到）
            'reused'          => $reused,
        ];
    }
}
