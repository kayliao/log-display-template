<?php

namespace App\Domain\Report;

use App\Core\Db\Connection;
use App\Core\Db\Db;

/**
 * 排程與實績匯入 —— 資料存取。
 *
 * ⚠ 這裡的 SQL 是依「假設的資料表結構」寫的範例，
 *   實際欄位名稱請對照公司資料庫調整。
 *
 * 唯一鍵是四個欄位一起：
 *   (plan_date, schedule_code, category, line_name)
 *
 * 這四欄一定要有唯一索引，否則「重跑一次匯入」會多出一整份重複資料，
 * 統整卡的合計就會變成兩倍——而且不會有任何錯誤訊息，最難查的那一種。
 */
class ScheduleImportRepository
{
    private function conn(): Connection
    {
        return Db::pg();
    }

    /**
     * 寫入一筆（有就更新、沒有就新增）。
     */
    public function upsert(array $row): int
    {
        $conn = $this->conn();

        return $conn->driver() === 'oracle'
            ? $conn->execute($this->oracleMergeSql(), $this->oracleBind($row))
            : $conn->execute($this->postgresUpsertSql(), $row);
    }

    /**
     * PostgreSQL：INSERT … ON CONFLICT DO UPDATE
     *
     * ON CONFLICT 後面那四欄要有唯一索引：
     *   CREATE UNIQUE INDEX ux_schedule_plan
     *       ON mes_schedule_plan (plan_date, schedule_code, category, line_name);
     */
    private function postgresUpsertSql(): string
    {
        return "INSERT INTO mes_schedule_plan
                    (plan_date, schedule_code, schedule_name, category, category_name,
                     sort_no, line_name, plan_qty, actual_qty, updated_at)
                VALUES
                    (CAST(:plan_date AS date), :schedule_code, :schedule_name, :category, :category_name,
                     :sort_no, :line_name, :plan_qty, :actual_qty, NOW())
                ON CONFLICT (plan_date, schedule_code, category, line_name) DO UPDATE
                   SET schedule_name = EXCLUDED.schedule_name,
                       category_name = EXCLUDED.category_name,
                       sort_no       = EXCLUDED.sort_no,
                       plan_qty      = EXCLUDED.plan_qty,
                       actual_qty    = EXCLUDED.actual_qty,
                       updated_at    = NOW()";
    }

    /**
     * Oracle：MERGE INTO … WHEN MATCHED / WHEN NOT MATCHED
     *
     * 同一個具名參數在 Oracle 只能出現一次，所以 INSERT 那一段要另外取名（_ins）。
     */
    private function oracleMergeSql(): string
    {
        return "MERGE INTO mes_schedule_plan t
                USING (SELECT TO_DATE(:plan_date, 'YYYY-MM-DD') AS plan_date,
                              :schedule_code AS schedule_code,
                              :category      AS category,
                              :line_name     AS line_name
                         FROM dual) s
                   ON (t.plan_date     = s.plan_date
                   AND t.schedule_code = s.schedule_code
                   AND t.category      = s.category
                   AND t.line_name     = s.line_name)
                WHEN MATCHED THEN
                    UPDATE SET t.schedule_name = :schedule_name,
                               t.category_name = :category_name,
                               t.sort_no       = :sort_no,
                               t.plan_qty      = :plan_qty,
                               t.actual_qty    = :actual_qty,
                               t.updated_at    = SYSDATE
                WHEN NOT MATCHED THEN
                    INSERT (plan_date, schedule_code, schedule_name, category, category_name,
                            sort_no, line_name, plan_qty, actual_qty, updated_at)
                    VALUES (TO_DATE(:plan_date_ins, 'YYYY-MM-DD'), :schedule_code_ins, :schedule_name_ins,
                            :category_ins, :category_name_ins, :sort_no_ins, :line_name_ins,
                            :plan_qty_ins, :actual_qty_ins, SYSDATE)";
    }

    /**
     * Oracle 版要的第二組參數。
     */
    private function oracleBind(array $row): array
    {
        foreach (['plan_date', 'schedule_code', 'schedule_name', 'category',
                  'category_name', 'sort_no', 'line_name', 'plan_qty', 'actual_qty'] as $key) {
            $row[$key . '_ins'] = $row[$key];
        }

        return $row;
    }

    /**
     * 一次寫入多筆，整批包在同一個交易裡。
     * 中途失敗就整批退回，不會出現「匯入到一半停住」要人工善後的狀況。
     *
     * @return int 實際寫入筆數
     */
    public function upsertMany(array $rows): int
    {
        $conn = $this->conn();

        return $conn->transaction(function () use ($rows) {
            $count = 0;

            foreach ($rows as $row) {
                $count += $this->upsert($row) > 0 ? 1 : 0;
            }

            return $count;
        });
    }

    /**
     * 檔案裡哪幾筆已經存在（預覽時標示「更新」還是「新增」）。
     *
     * 四欄組合鍵沒辦法用 IN，所以改成先把「這幾天、這些排程」的既有資料撈回來，
     * 再在 PHP 裡比對。一次匯入通常只有幾十列，撈回來的量很小。
     *
     * @return string[] 已存在的鍵（plan_date|schedule_code|category|line_name）
     */
    public function existingKeys(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $dates = [];
        foreach ($rows as $row) {
            $dates[$row['plan_date']] = true;
        }

        $dates = array_keys($dates);

        [$clause, $bind] = \App\Support\Sql::in('plan_date', $dates);

        $found = $this->conn()->select(
            "SELECT plan_date, schedule_code, category, line_name
               FROM mes_schedule_plan
              WHERE " . $clause,
            $bind
        );

        $keys = [];

        foreach ($found as $row) {
            $keys[] = implode('|', [
                substr((string) $row['plan_date'], 0, 10),
                $row['schedule_code'],
                $row['category'],
                $row['line_name'],
            ]);
        }

        return $keys;
    }
}
