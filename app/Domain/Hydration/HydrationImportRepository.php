<?php

namespace App\Domain\Hydration;

use App\Core\Db\Connection;
use App\Core\Db\Db;

/**
 * 水化紀錄匯入 —— 資料存取。
 *
 * 比對鍵是 (DRY_LOT_NO, HYD_SEQ)，跟資料表的唯一鍵一致
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
     * 回傳 0 表示「這一列存在、但已經封包了所以沒有被更新」——
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
     * UPDATE 那段的 WHERE t.pack_lot_no IS NULL 是第二道防線：
     * 從程式檢查完到真的寫入之間，別人可能剛好把它封包了。
     *
     * ⚠ 同一個具名參數在 Oracle 只能出現一次，所以 INSERT 那段要另外取名（_ins）。
     */
    private function oracleMergeSql(): string
    {
        return "MERGE INTO mes_hyd_wafer t
                USING (SELECT :dry_lot_no AS dry_lot_no, :hyd_seq AS hyd_seq FROM dual) s
                   ON (t.dry_lot_no = s.dry_lot_no AND t.hyd_seq = s.hyd_seq)
                WHEN MATCHED THEN
                    UPDATE SET t.hyd_date     = TO_DATE(:hyd_date, 'YYYY-MM-DD'),
                               t.qty          = :qty,
                               t.hyd_day_code = :hyd_day_code,
                               t.updated_at   = SYSDATE
                     WHERE t.pack_lot_no IS NULL
                WHEN NOT MATCHED THEN
                    INSERT (hyd_id, hyd_date, qty, dry_lot_no, hyd_day_code, hyd_seq,
                            source, created_at, updated_at)
                    VALUES (mes_hyd_wafer_seq.NEXTVAL,
                            TO_DATE(:hyd_date_ins, 'YYYY-MM-DD'), :qty_ins,
                            :dry_lot_no_ins, :hyd_day_code_ins, :hyd_seq_ins,
                            'IMPORT', SYSDATE, SYSDATE)";
    }

    /**
     * PostgreSQL 版（這一頁的資料在 Oracle，這裡列出來是為了換資料庫時照著改）。
     * ON CONFLICT 的欄位要有唯一索引：(dry_lot_no, hyd_seq)。
     */
    private function postgresUpsertSql(): string
    {
        return "INSERT INTO mes_hyd_wafer
                    (hyd_date, qty, dry_lot_no, hyd_day_code, hyd_seq, source, created_at, updated_at)
                VALUES
                    (CAST(:hyd_date AS date), :qty, :dry_lot_no, :hyd_day_code, :hyd_seq,
                     'IMPORT', NOW(), NOW())
                ON CONFLICT (dry_lot_no, hyd_seq) DO UPDATE
                   SET hyd_date     = EXCLUDED.hyd_date,
                       qty          = EXCLUDED.qty,
                       hyd_day_code = EXCLUDED.hyd_day_code,
                       updated_at   = NOW()
                 WHERE mes_hyd_wafer.pack_lot_no IS NULL";
    }

    private function oracleBind(array $row): array
    {
        foreach (['hyd_date', 'qty', 'dry_lot_no', 'hyd_day_code', 'hyd_seq'] as $key) {
            $row[$key . '_ins'] = $row[$key];
        }

        return $row;
    }
}
