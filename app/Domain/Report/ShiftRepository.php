<?php

namespace App\Domain\Report;

use App\Core\Db\Connection;
use App\Core\Db\Db;

/**
 * 班別產量報表 —— 資料存取。
 *
 * ⚠ 這裡的 SQL 是依「假設的資料表結構」寫的範例，
 *   實際欄位名稱請對照公司資料庫調整。
 *
 * 假設的資料表：
 *   mes_machine_shift(machine_id, stat_date,
 *                     d_ok, d_ng, d_oee,      -- 白班
 *                     n_ok, n_ng, n_oee)      -- 夜班
 */
class ShiftRepository
{
    private function conn(): Connection
    {
        return Db::pg();
    }

    /**
     * 查詢用的 SQL。
     *
     * 只回傳 SQL 與參數、不直接執行，分頁查詢與 CSV 匯出共用同一份。
     *
     * @return array{0:string, 1:array}
     */
    public function query(array $filters): array
    {
        $sql = "SELECT s.machine_id,
                       m.machine_name,
                       m.area,
                       s.d_ok,
                       s.d_ng,
                       s.d_oee,
                       s.n_ok,
                       s.n_ng,
                       s.n_oee,
                       COALESCE(s.d_ok, 0) + COALESCE(s.n_ok, 0) AS total_ok,
                       COALESCE(s.d_ng, 0) + COALESCE(s.n_ng, 0) AS total_ng
                  FROM mes_machine_shift s
                  JOIN mes_machine m ON m.machine_id = s.machine_id
                 WHERE 1 = 1";

        $bind = [];

        if (!empty($filters['start_date'])) {
            $sql .= " AND s.stat_date >= CAST(:start_date AS date)";
            $bind['start_date'] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $sql .= " AND s.stat_date <= CAST(:end_date AS date)";
            $bind['end_date'] = $filters['end_date'];
        }

        if (!empty($filters['area'])) {
            $sql .= " AND m.area = :area";
            $bind['area'] = $filters['area'];
        }

        if (!empty($filters['keyword'])) {
            $sql .= " AND (UPPER(s.machine_id) LIKE :kw OR UPPER(m.machine_name) LIKE :kw)";
            $bind['kw'] = '%' . strtoupper($filters['keyword']) . '%';
        }

        return [$sql, $bind];
    }
}
