<?php

namespace App\Domain\Hydration;

use App\Core\Db\Connection;
use App\Core\Db\Db;

/**
 * 水化排程匯入 —— 資料存取。
 *
 * 比對鍵是 (PPCUP_LOT, AQUA_CYCLE_NUM)，也就是資料表的主鍵
 * （見 docs/sql/hydration_oracle.sql 第 4 節）。
 */
class HydrationImportRepository
{
    public function conn(): Connection
    {
        return Db::oracle();
    }

    /**
     * 有就更新、沒有就新增。
     *
     * 回傳 0 表示「這一列存在、但已經有封包批號了所以沒有被更新」——
     * 匯入服務會把它當成一筆失敗回報，而不是默默當成成功。
     */
    public function upsert(array $row): int
    {
        $conn = $this->conn();

        return $conn->driver() === 'oracle'
            ? $conn->execute($this->oracleMergeSql(), $this->oracleBind($row))
            : $conn->execute($this->postgresUpsertSql(), $row);
    }

    /**
     * Oracle：MERGE INTO … WHEN MATCHED / WHEN NOT MATCHED
     *
     * UPDATE 那段的 WHERE packet_lot_temp_auto IS NULL 是第二道防線：
     * 從程式檢查完到真的寫入之間，機台可能剛好來要過這一列的號。
     *
     * ⚠ 同一個具名參數在 Oracle 只能出現一次，所以 INSERT 那段要另外取名（_ins）。
     */
    private function oracleMergeSql(): string
    {
        return "MERGE INTO aqua_schedule t
                USING (SELECT :ppcup_lot AS ppcup_lot, :aqua_cycle_num AS aqua_cycle_num FROM dual) s
                   ON (t.ppcup_lot = s.ppcup_lot AND t.aqua_cycle_num = s.aqua_cycle_num)
                WHEN MATCHED THEN
                    UPDATE SET t.aqua_schedule_date      = TO_DATE(:aqua_schedule_date, 'YYYY-MM-DD'),
                               t.qty                     = :qty,
                               t.aqua_schedule_date_code = :aqua_schedule_date_code
                     WHERE t.packet_lot_temp_auto IS NULL
                WHEN NOT MATCHED THEN
                    INSERT (aqua_schedule_date, ppcup_lot, qty, aqua_schedule_date_code, aqua_cycle_num)
                    VALUES (TO_DATE(:aqua_schedule_date_ins, 'YYYY-MM-DD'), :ppcup_lot_ins, :qty_ins,
                            :aqua_schedule_date_code_ins, :aqua_cycle_num_ins)";
    }

    /**
     * PostgreSQL 版（這一頁的資料在 Oracle，這裡列出來是為了換資料庫時照著改）。
     * ON CONFLICT 的欄位要有唯一索引：(ppcup_lot, aqua_cycle_num)。
     */
    private function postgresUpsertSql(): string
    {
        return "INSERT INTO aqua_schedule
                    (aqua_schedule_date, ppcup_lot, qty, aqua_schedule_date_code, aqua_cycle_num)
                VALUES
                    (CAST(:aqua_schedule_date AS date), :ppcup_lot, :qty,
                     :aqua_schedule_date_code, :aqua_cycle_num)
                ON CONFLICT (ppcup_lot, aqua_cycle_num) DO UPDATE
                   SET aqua_schedule_date      = EXCLUDED.aqua_schedule_date,
                       qty                     = EXCLUDED.qty,
                       aqua_schedule_date_code = EXCLUDED.aqua_schedule_date_code
                 WHERE aqua_schedule.packet_lot_temp_auto IS NULL";
    }

    private function oracleBind(array $row): array
    {
        foreach (['aqua_schedule_date', 'ppcup_lot', 'qty', 'aqua_schedule_date_code', 'aqua_cycle_num'] as $key) {
            $row[$key . '_ins'] = $row[$key];
        }

        return $row;
    }
}
