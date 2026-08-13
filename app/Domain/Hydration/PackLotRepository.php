<?php

namespace App\Domain\Hydration;

use App\Core\Db\Connection;
use App\Core\Db\Db;

/**
 * 封包批號取號 —— 資料存取。
 *
 * 這一支的每一句 SQL 都跟「併發」有關，改之前請先看
 * docs/sql/hydration_oracle.sql 的第 3 節。
 *
 * 兩條規矩：
 *   1. 鎖的順序固定「先鎖水化排程那一列、再鎖當日順序那一列」。
 *      兩支程式用相反順序鎖同樣兩列就會 deadlock。
 *   2. FOR UPDATE 的鎖會持有到 COMMIT，所以交易裡不要做慢動作
 *      （解析檔案、呼叫別的系統、寫 log 到遠端）。
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
     * WAIT 3：等最多三秒。等不到就讓機台收到「系統忙碌中」自己重試，
     * 不要讓對方的連線一直掛著（NOWAIT 太急，無限等最糟——
     * 一個卡住的交易會把後面所有取號都拖死）。
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
     * 鎖住當日順序那一列，回傳「下一個要發出去的順序值」。
     * 沒有這一天的列就回 null，由 createCounter() 建。
     */
    public function lockCounter(string $dateCode): ?int
    {
        $row = $this->conn()->selectOne(
            "SELECT NEXT_VAL
               FROM AQUA_PACKET_SEQ
              WHERE AQUA_SCHEDULE_DATE_CODE = :date_code
                FOR UPDATE WAIT 3",
            ['date_code' => $dateCode]
        );

        return $row === null ? null : (int) $row['next_val'];
    }

    /**
     * 建立當天的順序列。
     *
     * 兩支同時發現「今天還沒有這一列」時，其中一支會踩到主鍵衝突（ORA-00001）。
     * 這不是錯誤，是預期中的競爭：呼叫端接到 false 就重新 lockCounter() 一次。
     */
    public function createCounter(string $dateCode, int $value): bool
    {
        try {
            $this->conn()->execute(
                "INSERT INTO AQUA_PACKET_SEQ (AQUA_SCHEDULE_DATE_CODE, NEXT_VAL, UPDATED_AT)
                 VALUES (:date_code, :next_val, SYSDATE)",
                ['date_code' => $dateCode, 'next_val' => $value]
            );

            return true;
        } catch (\Throwable $e) {
            // 別人剛好也在建同一天的列
            return false;
        }
    }

    /**
     * 把順序推到下一個值。
     *
     * 下一個值是 PackLotNumber::next() 算出來的，不是在 SQL 裡 +3 ——
     * 進位規則（A9 的下一個是 B0 還是 B2）只能有一個地方說了算。
     */
    public function advanceCounter(string $dateCode, int $nextValue): void
    {
        $this->conn()->execute(
            "UPDATE AQUA_PACKET_SEQ
                SET NEXT_VAL = :next_val, UPDATED_AT = SYSDATE
              WHERE AQUA_SCHEDULE_DATE_CODE = :date_code",
            ['date_code' => $dateCode, 'next_val' => $nextValue]
        );
    }

    /**
     * 寫回封包批號。
     *
     * WHERE 多一個 packet_lot_temp_auto IS NULL：萬一鎖沒鎖到（有人繞過流程、
     * 或程式改壞了），這一句也不會蓋掉別人已經寫進去的號碼。
     *
     * @return int 影響列數。0 表示那一列已經有號碼了，呼叫端要重新讀。
     */
    public function writeBack(string $ppcupLot, int $cycleNum, string $packetLot): int
    {
        return $this->conn()->execute(
            "UPDATE AQUA_SCHEDULE
                SET PACKET_LOT_TEMP_AUTO = :packet_lot
              WHERE PPCUP_LOT            = :ppcup_lot
                AND AQUA_CYCLE_NUM       = :aqua_cycle_num
                AND PACKET_LOT_TEMP_AUTO IS NULL",
            [
                'packet_lot'     => $packetLot,
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
}
