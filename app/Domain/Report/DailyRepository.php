<?php

namespace App\Domain\Report;

use App\Core\Db\Connection;
use App\Core\Db\Db;

/**
 * 每日生產日報 —— 資料存取。
 *
 * 全系統的 SQL 只能出現在 Repository。
 * 這樣之後要改資料表、加索引、換資料庫，只需要看這一層。
 */
class DailyRepository
{
    /**
     * 這張報表要用哪個資料庫。
     *   Db::pg()      新資料（PostgreSQL）
     *   Db::oracle()  舊資料（Oracle）
     */
    private function conn(): Connection
    {
        return Db::pg();
    }

    /**
     * 查詢用的 SQL。
     *
     * 只回傳 SQL 與參數、不直接執行，
     * 這樣同一份 SQL 可以給「分頁查詢」和「CSV 匯出」兩邊共用。
     *
     * 注意：
     *   - 不要寫 LIMIT / ROWNUM，分頁交給 Paginator（它會依資料庫產生正確語法）
     *   - 參數一律用具名參數 :name（PostgreSQL 與 Oracle 都支援）
     *   - 條件用「有給才加」的寫法，不要用一長串 CASE WHEN 硬塞，那樣用不到索引
     *
     * @return array{0:string, 1:array}
     */
    public function query(array $filters): array
    {
        $sql = "SELECT t.col1,
                       t.col2,
                       t.col3
                  FROM your_table t
                 WHERE 1 = 1";

        $bind = [];

        if (!empty($filters['start_date'])) {
            // PostgreSQL 寫法；Oracle 請改成
            //   t.created_at >= TO_DATE(:start_date, 'YYYY-MM-DD')
            $sql .= " AND t.created_at >= CAST(:start_date AS date)";
            $bind['start_date'] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $sql .= " AND t.created_at < CAST(:end_date AS date) + 1";
            $bind['end_date'] = $filters['end_date'];
        }

        if (!empty($filters['type'])) {
            $sql .= " AND t.type = :type";
            $bind['type'] = $filters['type'];
        }

        if (!empty($filters['keyword'])) {
            $sql .= " AND (UPPER(t.col1) LIKE :kw OR UPPER(t.col2) LIKE :kw)";
            $bind['kw'] = '%' . strtoupper($filters['keyword']) . '%';
        }

        return [$sql, $bind];
    }

    /**
     * 單筆資料（放大鏡彈窗用）。
     */
    public function find(string $id): ?array
    {
        return $this->conn()->selectOne(
            "SELECT * FROM your_table WHERE col1 = :id",
            ['id' => $id]
        );
    }
}
