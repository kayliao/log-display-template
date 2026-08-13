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
        return "MERGE INTO AQUA_SCHEDULE T
                USING (SELECT :ppcup_lot AS PPCUP_LOT, :aqua_cycle_num AS AQUA_CYCLE_NUM FROM DUAL) S
                   ON (T.PPCUP_LOT = S.PPCUP_LOT AND T.AQUA_CYCLE_NUM = S.AQUA_CYCLE_NUM)
                WHEN MATCHED THEN
                    UPDATE SET T.AQUA_SCHEDULE_DATE      = TO_DATE(:aqua_schedule_date, 'YYYY-MM-DD'),
                               T.QTY                     = :qty,
                               T.AQUA_SCHEDULE_DATE_CODE = :aqua_schedule_date_code
                     WHERE T.PACKET_LOT_TEMP_AUTO IS NULL
                WHEN NOT MATCHED THEN
                    INSERT (AQUA_SCHEDULE_DATE, PPCUP_LOT, QTY, AQUA_SCHEDULE_DATE_CODE, AQUA_CYCLE_NUM)
                    VALUES (TO_DATE(:aqua_schedule_date_ins, 'YYYY-MM-DD'), :ppcup_lot_ins, :qty_ins,
                            :aqua_schedule_date_code_ins, :aqua_cycle_num_ins)";
    }

    /**
     * PostgreSQL 版（這一頁的資料在 Oracle，這裡列出來是為了換資料庫時照著改）。
     * ON CONFLICT 的欄位要有唯一索引：(ppcup_lot, aqua_cycle_num)。
     */
    private function postgresUpsertSql(): string
    {
        // PostgreSQL 沒加引號的識別字會被折成小寫，所以這一段照 PG 的習慣用小寫
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
