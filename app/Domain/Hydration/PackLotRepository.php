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
 *   1. 鎖的順序固定「先鎖水化紀錄那一列、再鎖當日順序那一列」。
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
     * WAIT 3：等最多三秒。等不到就讓呼叫端收到「系統忙碌中」自己重試，
     * 不要讓對方的連線一直掛著（NOWAIT 太急，無限等最糟——
     * 一個卡住的交易會把後面所有取號都拖死）。
     */
    public function lockLatestRow(string $dryLotNo): ?array
    {
        return $this->conn()->selectOne(
            "SELECT hyd_id, dry_lot_no, hyd_day_code, hyd_seq, pack_lot_no, pre_pack_lot_no
               FROM mes_hyd_wafer
              WHERE dry_lot_no = :dry_lot_no
                AND hyd_seq = (SELECT MAX(hyd_seq) FROM mes_hyd_wafer WHERE dry_lot_no = :dry_lot_no)
                FOR UPDATE WAIT 3",
            ['dry_lot_no' => $dryLotNo]
        );
    }

    /**
     * 鎖住當日順序那一列，回傳「下一個要發出去的順序值」。
     * 沒有這一天的列就回 null，由 createCounter() 建。
     */
    public function lockCounter(string $dayCode): ?int
    {
        $row = $this->conn()->selectOne(
            "SELECT next_val
               FROM mes_hyd_pack_seq
              WHERE day_code = :day_code
                FOR UPDATE WAIT 3",
            ['day_code' => $dayCode]
        );

        return $row === null ? null : (int) $row['next_val'];
    }

    /**
     * 建立當天的順序列。
     *
     * 兩支同時發現「今天還沒有這一列」時，其中一支會踩到主鍵衝突（ORA-00001）。
     * 這不是錯誤，是預期中的競爭：呼叫端接到 false 就重新 lockCounter() 一次。
     */
    public function createCounter(string $dayCode, int $value): bool
    {
        try {
            $this->conn()->execute(
                "INSERT INTO mes_hyd_pack_seq (day_code, next_val, updated_at)
                 VALUES (:day_code, :next_val, SYSDATE)",
                ['day_code' => $dayCode, 'next_val' => $value]
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
    public function advanceCounter(string $dayCode, int $nextValue): void
    {
        $this->conn()->execute(
            "UPDATE mes_hyd_pack_seq
                SET next_val = :next_val, updated_at = SYSDATE
              WHERE day_code = :day_code",
            ['day_code' => $dayCode, 'next_val' => $nextValue]
        );
    }

    /**
     * 寫回預配封包批號。
     *
     * WHERE 多一個 pre_pack_lot_no IS NULL：萬一鎖沒鎖到（有人繞過流程、
     * 或程式改壞了），這一句也不會蓋掉別人已經寫進去的號碼。
     *
     * @return int 影響列數。0 表示那一列已經有號碼了，呼叫端要重新讀。
     */
    public function writeBack(int $hydId, string $packLotNo): int
    {
        return $this->conn()->execute(
            "UPDATE mes_hyd_wafer
                SET pre_pack_lot_no = :pre_pack_lot_no,
                    updated_at      = SYSDATE
              WHERE hyd_id          = :hyd_id
                AND pre_pack_lot_no IS NULL",
            ['hyd_id' => $hydId, 'pre_pack_lot_no' => $packLotNo]
        );
    }

    /**
     * 重新讀一列（寫回失敗時要把既有的號碼撈出來回給呼叫端）。
     */
    public function findRow(int $hydId): ?array
    {
        return $this->conn()->selectOne(
            "SELECT hyd_id, dry_lot_no, hyd_day_code, hyd_seq, pack_lot_no, pre_pack_lot_no
               FROM mes_hyd_wafer
              WHERE hyd_id = :hyd_id",
            ['hyd_id' => $hydId]
        );
    }
}
