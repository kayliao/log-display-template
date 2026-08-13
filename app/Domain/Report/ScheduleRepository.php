<?php

namespace App\Domain\Report;

use App\Core\Db\Connection;
use App\Core\Db\Db;
use App\Support\Sql;

/**
 * 排程達成率 —— 資料存取。
 *
 * ⚠ 這裡的 SQL 是依「假設的資料表結構」寫的範例，
 *   實際欄位名稱請對照公司資料庫調整。
 *
 * 假設的資料表：
 *   mes_schedule_plan(plan_date, schedule_code, schedule_name,
 *                     category, category_name, sort_no, line_name,
 *                     plan_qty, actual_qty, updated_at)
 *
 *   一天一個排程一個產品別一條線一列，例如
 *     2026-08-12 / HYD 水化 / WHITE 白片 / 一線 / 預計 4000 / 實際 3820
 *
 * 這一支有兩種查法，兩種都從同一份 WHERE 條件長出來：
 *   query()   明細（一列一條線），給表格與 CSV 匯出用
 *   summary() 依產品別彙總，給達成率統整卡用
 *
 * 統整卡刻意不從明細自己加總，而是讓資料庫算 SUM——
 * 明細有分頁，前端手上只有當頁的資料，加起來會是「這一頁的合計」。
 */
class ScheduleRepository
{
    private function conn(): Connection
    {
        return Db::pg();
    }

    /**
     * 共用的 WHERE 條件。
     *
     * @return array{0:string, 1:array}
     */
    private function where(array $filters): array
    {
        $sql  = ' WHERE 1 = 1';
        $bind = [];

        // 日期：統整卡看的是「某一天」，所以這裡是單日而不是區間
        if (!empty($filters['plan_date'])) {
            $sql .= ' AND p.plan_date = CAST(:plan_date AS date)';
            $bind['plan_date'] = $filters['plan_date'];
        }

        if (!empty($filters['schedule_code'])) {
            $sql .= ' AND p.schedule_code = :schedule_code';
            $bind['schedule_code'] = $filters['schedule_code'];
        }

        if (!empty($filters['category'])) {
            $sql .= ' AND p.category = :category';
            $bind['category'] = $filters['category'];
        }

        if (!empty($filters['keyword'])) {
            $sql .= ' AND (UPPER(p.line_name) LIKE :kw OR UPPER(p.schedule_name) LIKE :kw)';
            $bind['kw'] = '%' . strtoupper($filters['keyword']) . '%';
        }

        // 一次指定多條線（從排程表複製一整欄貼進來）
        if (!empty($filters['line_names'])) {
            [$clause, $inBind] = Sql::in('p.line_name', $filters['line_names']);

            $sql  .= ' AND ' . $clause;
            $bind  = array_merge($bind, $inBind);
        }

        return [$sql, $bind];
    }

    /**
     * 明細查詢。只回傳 SQL 與參數，分頁與 CSV 匯出共用同一份。
     *
     * 達成率與差異在 SQL 裡算好，畫面、匯出檔、統整卡才不會各算各的。
     * 除以 0 要擋掉——某條線今天沒有排程時 plan_qty 會是 0。
     *
     * @return array{0:string, 1:array}
     */
    public function query(array $filters): array
    {
        [$where, $bind] = $this->where($filters);

        $sql = "SELECT p.plan_date,
                       p.schedule_code,
                       p.schedule_name,
                       p.category,
                       p.category_name,
                       p.line_name,
                       p.plan_qty,
                       p.actual_qty,
                       COALESCE(p.actual_qty, 0) - COALESCE(p.plan_qty, 0) AS diff_qty,
                       CASE WHEN COALESCE(p.plan_qty, 0) > 0
                            THEN ROUND(COALESCE(p.actual_qty, 0) * 100.0 / p.plan_qty, 1)
                            ELSE NULL
                       END AS achieve_rate,
                       p.updated_at
                  FROM mes_schedule_plan p" . $where;

        return [$sql, $bind];
    }

    /**
     * 依產品別彙總（白片 / 彩片 各自的預計與實際）。
     *
     * 只回傳 plan / actual 兩個數字，達成率與佔比交給 achievement 元件算——
     * 後端算一份、前端再算一份的話，四捨五入的位數遲早會對不起來。
     */
    public function summary(array $filters): array
    {
        [$where, $bind] = $this->where($filters);

        return $this->conn()->select(
            "SELECT p.sort_no,
                    p.category,
                    p.category_name,
                    SUM(COALESCE(p.plan_qty, 0))   AS plan_qty,
                    SUM(COALESCE(p.actual_qty, 0)) AS actual_qty,
                    MAX(p.updated_at)              AS updated_at
               FROM mes_schedule_plan p" . $where .
          " GROUP BY p.sort_no, p.category, p.category_name
             ORDER BY p.sort_no",
            $bind
        );
    }

    /**
     * 排程清單（查詢條件的下拉選單用）。
     */
    public function schedules(): array
    {
        return $this->conn()->select(
            "SELECT DISTINCT schedule_code, schedule_name
               FROM mes_schedule_plan
              ORDER BY schedule_code"
        );
    }
}
