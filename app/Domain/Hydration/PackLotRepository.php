<?php

namespace App\Domain\Hydration;

use App\Core\Db\Connection;
use App\Core\Db\Db;

/**
 * 封包批號取號 —— 資料存取。
 *
 * 這一支的每一句 SQL 都跟「併發」有關，改之前請先看
 * docs/sql/hydration_oracle.sql 的第 2、3 節。
 *
 * ── 為什麼沒有「當日順序」那張計數表 ────────────────────────
 *
 * 下一個號碼是**從資料算出來的**：抓當天已經發出去的號碼裡最大的那一個，
 * 再往前推一步。不另外維護一張計數表，理由：
 *
 *   單一真相   號碼就在 PACKET_LOT_TEMP_AUTO 裡。有人手動補號、修資料、
 *              清掉幾列，下一號永遠算得對。計數表會跟真實資料對不起來，
 *              而且對不起來的時候它照樣發號，發到重複才被唯一鍵擋下。
 *   少一張表   不用多備份、多收統計，也不用有人知道它存在
 *   沒有每日維護   本來計數表也不需要人工每天建（第一次取號時程式自己建），
 *              但少一張表就是少一件要解釋的事
 *
 * 代價：兩支同時取號會算出同一個號。這件事由
 * UX_AQUA_SCHEDULE_PACKET 唯一鍵擋下，Service 收到唯一鍵衝突就重算重試。
 * 一天最多 120 個號，撞在一起的機率極低，重試三次幾乎不可能還失敗。
 */
class PackLotRepository
{
    public function conn(): Connection
    {
        return Db::oracle();
    }

    /**
     * 鎖住並取回這個乾片批號「最新一次水化」那一列。
     *
     * 這個鎖擋的是「同一個乾片批號、兩支同時來要號」；
     * 不同批號之間不會互相等，那一段交給唯一鍵與重試處理。
     *
     * WAIT 3：等最多三秒。等不到就讓機台收到「系統忙碌中」自己重試，
     * 不要讓對方的連線一直掛著（NOWAIT 太急，無限等最糟）。
     */
    public function lockLatestRow(string $ppcupLot): ?array
    {
        return $this->conn()->selectOne(
            "SELECT PPCUP_LOT, AQUA_CYCLE_NUM, AQUA_SCHEDULE_DATE_CODE, PACKET_LOT_TEMP_AUTO
               FROM AQUA_SCHEDULE
              WHERE PPCUP_LOT = :ppcup_lot
                AND AQUA_CYCLE_NUM = (SELECT MAX(AQUA_CYCLE_NUM) FROM AQUA_SCHEDULE WHERE PPCUP_LOT = :ppcup_lot)
                FOR UPDATE WAIT 3",
            ['ppcup_lot' => $ppcupLot]
        );
    }

    /**
     * 當天已經發出去的號碼裡，順序最大的那兩碼；一個都還沒發就回 null。
     *
     * 【為什麼可以直接用字串的 MAX】
     * 順序的編碼是「前一碼 0-9 之後接 A-Z、後一碼 0-9」，
     * 而 ASCII 裡 '0'-'9' 剛好排在 'A'-'Z' 前面，
     * 所以字串比大小的順序跟數值大小完全一致（'99' < 'A0' < 'B2'）。
     * ⚠ 哪天編碼改用別的字元集，這個前提就不成立了，要改成把號碼撈回來自己比。
     *
     * 【為什麼是 SUBSTR(..., -2) 而不是整串比】
     * 封包批號的前段是乾片批號，不同批號的前段不一樣，
     * 整串比大小會變成「比乾片批號」，跟順序無關。
     *
     * 【索引】
     * IX_AQUA_SCHEDULE_SEQ 是照這個運算式建的 function-based index，
     * 所以這一句是 INDEX RANGE SCAN (MIN/MAX)，只讀一個葉節點。
     * 查詢的寫法必須跟索引的運算式**一模一樣**，改這裡要順便改索引。
     */
    public function maxSeqCode(string $dateCode): ?string
    {
        $row = $this->conn()->selectOne(
            "SELECT MAX(SUBSTR(PACKET_LOT_TEMP_AUTO, -2)) AS LAST_SEQ
               FROM AQUA_SCHEDULE
              WHERE AQUA_SCHEDULE_DATE_CODE = :date_code
                AND PACKET_LOT_TEMP_AUTO IS NOT NULL",
            ['date_code' => $dateCode]
        );

        $value = $row['last_seq'] ?? null;

        return ($value === null || $value === '') ? null : (string) $value;
    }

    /**
     * 寫回封包批號，順便記下是誰、什麼時候寫的。
     *
     * WHERE 多一個 PACKET_LOT_TEMP_AUTO IS NULL：萬一鎖沒鎖到（有人繞過流程、
     * 或程式改壞了），這一句也不會蓋掉別人已經寫進去的號碼。
     *
     * @return int 影響列數。0 表示那一列已經有號碼了，呼叫端要重新讀。
     */
    public function writeBack(string $ppcupLot, int $cycleNum, string $packetLot, string $updateUser): int
    {
        return $this->conn()->execute(
            "UPDATE AQUA_SCHEDULE
                SET PACKET_LOT_TEMP_AUTO = :packet_lot,
                    UPDATE_USER          = :update_user,
                    UPDATE_TIME          = SYSDATE
              WHERE PPCUP_LOT            = :ppcup_lot
                AND AQUA_CYCLE_NUM       = :aqua_cycle_num
                AND PACKET_LOT_TEMP_AUTO IS NULL",
            [
                'packet_lot'     => $packetLot,
                'update_user'    => $updateUser,
                'ppcup_lot'      => $ppcupLot,
                'aqua_cycle_num' => $cycleNum,
            ]
        );
    }

    /**
     * 重新讀一列（寫回失敗時要把既有的號碼撈出來回給機台）。
     */
    public function findRow(string $ppcupLot, int $cycleNum): ?array
    {
        return $this->conn()->selectOne(
            "SELECT PPCUP_LOT, AQUA_CYCLE_NUM, AQUA_SCHEDULE_DATE_CODE, PACKET_LOT_TEMP_AUTO
               FROM AQUA_SCHEDULE
              WHERE PPCUP_LOT = :ppcup_lot
                AND AQUA_CYCLE_NUM = :aqua_cycle_num",
            ['ppcup_lot' => $ppcupLot, 'aqua_cycle_num' => $cycleNum]
        );
    }

    /**
     * 這個例外是不是「唯一鍵衝突」。
     *
     * 取號時撞到它是**預期中的競爭**，不是壞掉：表示別人剛好也算出同一個號。
     * Oracle 是 ORA-00001，PostgreSQL 是 SQLSTATE 23505。
     */
    public static function isDuplicate(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return strpos($message, 'ORA-00001') !== false
            || strpos($message, '23505') !== false;
    }
}
