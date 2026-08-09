<?php

namespace App\Core\Db;

use App\Core\Logger;

/**
 * 示範用的假連線。
 *
 * 實作跟真實連線同一個介面，所以整套系統（Repository、Paginator、
 * 排序、分頁、CSV 匯出）完全不需要為了示範模式做任何特例判斷。
 *
 * 它不會真的解析 SQL，只做三件事：
 *   1. 從 SQL 裡認出資料表名稱，決定要回傳哪一組示範資料
 *   2. 認出 COUNT(*) 就回傳筆數
 *   3. 認出 ORDER BY / LIMIT / OFFSET 就把資料排序、切片
 *
 * 由 config/app.php 的 demo_mode 開關控制，預設為開，
 * 接上真實資料庫後請關掉。開著的時候畫面上會有明顯提示。
 */
class DemoConnection extends BaseConnection
{
    public function __construct(string $name = 'demo')
    {
        // 對外宣稱是 pgsql，Paginator 就會產生 LIMIT/OFFSET 語法，比較好解析
        $this->driver = 'pgsql';
        $this->name   = $name;
    }

    public function select(string $sql, array $bind = []): array
    {
        $rows = DemoData::forSql($sql);

        /**
         * 分頁用的總筆數查詢。
         *
         * 認 Paginator 產生的 pager_count 別名，而不是認 COUNT(*)——
         * 報表自己的統計 SQL（例如「各事件類型幾筆」）也會有 COUNT(*)，
         * 用 COUNT(*) 判斷會把那種查詢誤當成總筆數，導致統計表整欄空白。
         */
        if (stripos($sql, 'pager_count') !== false) {
            preg_match('/count\(\*\)\s+as\s+(\w+)/i', $sql, $m);

            return [[strtolower($m[1] ?? 'cnt') => count($this->applyFilters($rows, $bind, $sql))]];
        }

        $rows = $this->applyFilters($rows, $bind, $sql);
        $rows = $this->applyDistinct($rows, $sql);
        $rows = $this->applyGroupBy($rows, $sql);
        $rows = $this->applyOrderBy($rows, $sql);
        $rows = $this->applyLimit($rows, $sql);

        return $rows;
    }

    public function execute(string $sql, array $bind = []): int
    {
        // 示範模式不真的寫入，只記一筆 log，讓人知道有被呼叫到
        Logger::info('示範模式：略過資料寫入', ['sql' => substr(preg_replace('/\s+/', ' ', $sql), 0, 120)]);

        return 1;
    }

    public function begin(): void
    {
    }

    public function commit(): void
    {
    }

    public function rollBack(): void
    {
    }

    public function raw()
    {
        return null;
    }

    /**
     * 依繫結參數做簡單過濾，讓查詢條件在示範模式下也有反應。
     */
    private function applyFilters(array $rows, array $bind, string $sql): array
    {
        foreach ($bind as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            // 關鍵字：任一欄位包含就算符合
            if ($key === 'kw') {
                $needle = strtoupper(trim((string) $value, '%'));
                $rows = array_values(array_filter($rows, function ($row) use ($needle) {
                    foreach ($row as $v) {
                        if (is_scalar($v) && strpos(strtoupper((string) $v), $needle) !== false) {
                            return true;
                        }
                    }

                    return false;
                }));
                continue;
            }

            // 日期區間不過濾（示範資料本來就都在近期）
            if (in_array($key, ['start_date', 'end_date', 'log_time', 'mins', 'role'], true)) {
                continue;
            }

            // 其餘：欄位值相等
            if ($rows !== [] && array_key_exists($key, $rows[0])) {
                $rows = array_values(array_filter($rows, function ($row) use ($key, $value) {
                    return (string) $row[$key] === (string) $value;
                }));
            }
        }

        return $rows;
    }

    /**
     * SELECT DISTINCT：只留下被選取的欄位，並去掉重複。
     *
     * 沒有這一段的話，「SELECT DISTINCT area FROM mes_machine」在示範模式下
     * 會回傳全部機台，廠區下拉就變成 A、A、A…重複四十幾個選項。
     *
     * 只處理單純的欄位列表，遇到 *、函式或認不得的欄位就原樣放行——
     * 這是示範資料的簡化實作，寧可少做也不要做錯。
     */
    private function applyDistinct(array $rows, string $sql): array
    {
        if (!preg_match('/select\s+distinct\s+(.+?)\s+from\s/is', $sql, $m)) {
            return $rows;
        }

        $columns = [];

        foreach (explode(',', $m[1]) as $expr) {
            $expr = trim(preg_replace('/\s+as\s+\w+$/i', '', trim($expr)));

            if ($expr === '*' || strpos($expr, '(') !== false) {
                return $rows;
            }

            // 去掉資料表別名：m.area => area
            $dot       = strrchr($expr, '.');
            $columns[] = strtolower($dot ? substr($dot, 1) : $expr);
        }

        $seen = [];
        $out  = [];

        foreach ($rows as $row) {
            $picked = [];

            foreach ($columns as $column) {
                if (!array_key_exists($column, $row)) {
                    return $rows;
                }
                $picked[$column] = $row[$column];
            }

            $key = implode("\0", array_map('strval', $picked));

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[]      = $picked;
            }
        }

        return $out;
    }

    /**
     * 只支援 GROUP BY 單一欄位 + COUNT，給「類型統計」那張表用。
     */
    private function applyGroupBy(array $rows, string $sql): array
    {
        if (!preg_match('/group\s+by\s+([a-z_][a-z0-9_]*)/i', $sql, $m)) {
            return $rows;
        }

        $column = strtolower($m[1]);
        $counts = [];

        foreach ($rows as $row) {
            if (!array_key_exists($column, $row)) {
                continue;
            }
            $key          = (string) $row[$column];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        arsort($counts);

        $out = [];
        foreach ($counts as $value => $count) {
            $out[] = [$column => $value, 'cnt' => $count];
        }

        return $out;
    }

    private function applyOrderBy(array $rows, string $sql): array
    {
        if (!preg_match('/order\s+by\s+([a-z_][a-z0-9_.]*)\s*(asc|desc)?/i', $sql, $m)) {
            return $rows;
        }

        $column = strtolower(substr(strrchr($m[1], '.') ?: $m[1], strrchr($m[1], '.') ? 1 : 0));
        $desc   = isset($m[2]) && strtolower($m[2]) === 'desc';

        if ($rows === [] || !array_key_exists($column, $rows[0])) {
            return $rows;
        }

        usort($rows, function ($a, $b) use ($column, $desc) {
            $x = $a[$column];
            $y = $b[$column];

            $result = (is_numeric($x) && is_numeric($y))
                ? ($x <=> $y)
                : strcmp((string) $x, (string) $y);

            return $desc ? -$result : $result;
        });

        return $rows;
    }

    private function applyLimit(array $rows, string $sql): array
    {
        $limit  = preg_match('/limit\s+(\d+)/i', $sql, $m)  ? (int) $m[1] : null;
        $offset = preg_match('/offset\s+(\d+)/i', $sql, $m2) ? (int) $m2[1] : 0;

        if ($limit === null) {
            return $rows;
        }

        return array_slice($rows, $offset, $limit);
    }
}
