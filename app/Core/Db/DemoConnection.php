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
        // IN (...) 的那一組參數要先合起來看，逐個比對會把資料濾成空的
        [$rows, $bind] = $this->applyInFilters($rows, $bind);

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

            /**
             * 日期條件。
             *
             * 示範資料的欄位名一律叫 xxx_date，所以認得出來的就真的過濾，
             * 認不出來（例如 Log 用的是 log_time）就跳過不管 ——
             * 「今日統整」那種卡片如果連天數都不分，示範模式看起來會很怪。
             */
            if (in_array($key, ['start_date', 'end_date', 'stat_date'], true)) {
                $rows = $this->applyDateFilter($rows, $key, (string) $value);
                continue;
            }

            if (in_array($key, ['log_time', 'mins', 'role'], true)) {
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
     * 依日期參數過濾。
     *
     * 找出資料裡第一個像日期的欄位（名字結尾是 _date 或就叫 date），
     * 找不到就原樣放行 —— 這是示範資料的簡化實作，寧可少做也不要做錯。
     *
     *   start_date  >= 這一天
     *   end_date    <= 這一天
     *   stat_date   剛好是這一天
     */
    private function applyDateFilter(array $rows, string $key, string $value): array
    {
        if ($rows === [] || $value === '') {
            return $rows;
        }

        $column = null;

        foreach (array_keys($rows[0]) as $name) {
            if (preg_match('/(^|_)date$/', (string) $name)) {
                $column = $name;
                break;
            }
        }

        if ($column === null) {
            return $rows;
        }

        $date = substr($value, 0, 10);

        return array_values(array_filter($rows, function ($row) use ($column, $key, $date) {
            $rowDate = substr((string) $row[$column], 0, 10);

            if ($key === 'start_date') {
                return $rowDate >= $date;
            }

            if ($key === 'end_date') {
                return $rowDate <= $date;
            }

            return $rowDate === $date;
        }));
    }

    /**
     * 處理 Sql::in() 產生的那一組參數。
     *
     * Sql::in('machine_id', ['M-101','M-102']) 會產生
     *   machine_id_0 => 'M-101'
     *   machine_id_1 => 'M-102'
     *
     * 一個一個當成「欄位 = 值」去比對的話，第一個就把資料濾成只剩 M-101，
     * 第二個再濾一次就全空了。所以要先把同一組合起來當成 IN 處理。
     *
     * 認的規則是「去掉結尾的 _數字之後，剛好是資料裡的某個欄位」。
     *
     * @return array{0:array, 1:array} 過濾後的資料，以及剩下還沒處理的參數
     */
    private function applyInFilters(array $rows, array $bind): array
    {
        if ($rows === []) {
            return [$rows, $bind];
        }

        $groups = [];

        foreach ($bind as $key => $value) {
            if (!preg_match('/^(.+)_\d+$/', (string) $key, $m)) {
                continue;
            }

            $column = strtolower($m[1]);

            if (array_key_exists($column, $rows[0])) {
                $groups[$column][] = (string) $value;
                unset($bind[$key]);
            }
        }

        foreach ($groups as $column => $values) {
            $rows = array_values(array_filter($rows, function ($row) use ($column, $values) {
                return in_array((string) $row[$column], $values, true);
            }));
        }

        return [$rows, $bind];
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
     * GROUP BY（可多欄）搭配 COUNT / SUM / MAX / MIN。
     *
     * 用在兩個地方：Log 的「類型統計」（COUNT）與排程達成率的統整卡（SUM）。
     * 統整卡如果在示範模式下算不出合計，這套模板剛下載下來就會看到一張空卡片。
     *
     * 這是示範資料的簡化實作，只認得「聚合函式 AS 別名」這種最單純的寫法，
     * 認不得的就原樣放行 —— 寧可少做也不要做錯。
     */
    private function applyGroupBy(array $rows, string $sql): array
    {
        $aggregates = $this->parseAggregates($sql);
        $columns    = [];

        if (preg_match('/group\s+by\s+(.+?)(?=\s+order\s+by|\s+having|\s*$)/is', $sql, $m)) {
            foreach (explode(',', $m[1]) as $expr) {
                $expr = trim($expr);

                if ($expr === '' || strpos($expr, '(') !== false) {
                    return $rows;    // 認不得的運算式，不要亂算
                }

                $columns[] = $this->columnName($expr);
            }
        } elseif ($aggregates === []) {
            return $rows;            // 沒有 GROUP BY 也沒有聚合，就是一般查詢
        }

        /**
         * 沒有 GROUP BY 但 SELECT 裡有聚合（SELECT COUNT(*), SUM(qty) FROM …）
         * 就是「整批算成一列」，而且沒有資料時也要回一列（COUNT 是 0 不是沒有列）。
         */
        if ($rows === []) {
            return $columns === [] ? [$this->emptyAggregateRow($aggregates)] : $rows;
        }

        foreach ($columns as $column) {
            if (!array_key_exists($column, $rows[0])) {
                return $rows;
            }
        }

        $buckets = [];
        $seen    = [];      // COUNT(DISTINCT …) 用：每一組已經看過哪些值

        foreach ($rows as $row) {
            $values = [];

            foreach ($columns as $column) {
                $values[$column] = $row[$column];
            }

            $key = implode("\0", array_map('strval', $values));

            if (!isset($buckets[$key])) {
                $buckets[$key] = array_merge($values, $this->emptyAggregateRow($aggregates));
            }

            foreach ($aggregates as $alias => $agg) {
                $value = $agg['column'] === '*' ? 1 : ($row[$agg['column']] ?? null);
                $now   = $buckets[$key][$alias];

                switch ($agg['fn']) {
                    case 'count':
                        // COUNT(*) 算全部；COUNT(欄位) 只算不是 NULL 的；DISTINCT 再去掉重複
                        if ($value === null) {
                            break;
                        }

                        if (!empty($agg['distinct'])) {
                            $mark = $key . "\0" . $alias . "\0" . $value;

                            if (isset($seen[$mark])) {
                                break;
                            }

                            $seen[$mark] = true;
                        }

                        $buckets[$key][$alias] = $now + 1;
                        break;

                    case 'sum':
                        if ($value !== null) {
                            $buckets[$key][$alias] = (float) $now + (float) $value;
                        }
                        break;

                    case 'max':
                        $buckets[$key][$alias] = ($now === null || $value > $now) ? $value : $now;
                        break;

                    case 'min':
                        $buckets[$key][$alias] = ($now === null || $value < $now) ? $value : $now;
                        break;
                }
            }
        }

        return array_values($buckets);
    }

    /**
     * 聚合欄位的初始值：COUNT 從 0 開始，其餘從 NULL 開始（跟 SQL 一致）。
     */
    private function emptyAggregateRow(array $aggregates): array
    {
        $row = [];

        foreach ($aggregates as $alias => $agg) {
            $row[$alias] = $agg['fn'] === 'count' ? 0 : null;
        }

        return $row;
    }

    /**
     * 從 SELECT 清單裡認出聚合欄位：SUM(COALESCE(p.qty, 0)) AS qty => ['qty' => ['fn' => 'sum', 'column' => 'qty']]
     *
     * 只看最外層的 SELECT（第一個 FROM 之前），
     * 否則子查詢裡的 COUNT 會被誤認成這一層的聚合。
     */
    private function parseAggregates(string $sql): array
    {
        if (!preg_match('/^\s*select\s+(.*?)\s+from\s/is', $sql, $m)) {
            return [];
        }

        $list = $m[1];
        $out  = [];

        if (!preg_match_all('/\b(count|sum|max|min)\s*\(/i', $list, $found, PREG_OFFSET_CAPTURE)) {
            return $out;
        }

        foreach ($found[1] as $i => $hit) {
            $fn    = strtolower($hit[0]);
            $open  = $found[0][$i][1] + strlen($found[0][$i][0]) - 1;   // 左括號的位置
            $depth = 0;
            $close = null;

            // 括號可能有巢狀（SUM(COALESCE(x, 0))），所以要自己數
            for ($p = $open; $p < strlen($list); $p++) {
                if ($list[$p] === '(') {
                    $depth++;
                } elseif ($list[$p] === ')') {
                    $depth--;

                    if ($depth === 0) {
                        $close = $p;
                        break;
                    }
                }
            }

            if ($close === null || !preg_match('/^\s*as\s+(\w+)/i', substr($list, $close + 1), $alias)) {
                continue;    // 沒有別名就認不出要放進哪一欄，跳過
            }

            $inner = substr($list, $open + 1, $close - $open - 1);

            $out[strtolower($alias[1])] = [
                'fn'       => $fn,
                'column'   => $this->aggregateColumn($inner),
                'distinct' => (bool) preg_match('/^\s*distinct\s+/i', $inner),
            ];
        }

        return $out;
    }

    /**
     * 從聚合函式的括號內容裡挑出真正的欄位。
     * COALESCE(p.plan_qty, 0) => plan_qty、* => *
     */
    private function aggregateColumn(string $inner): string
    {
        $inner = trim($inner);

        if ($inner === '*') {
            return '*';
        }

        preg_match_all('/[a-z_][a-z0-9_.]*\s*\(?/i', $inner, $tokens);

        foreach ($tokens[0] as $token) {
            // 後面接著左括號的是函式名（COALESCE、NULLIF…），不是欄位
            if (substr(rtrim($token), -1) === '(') {
                continue;
            }

            $token = trim($token);

            // SQL 關鍵字不是欄位（CASE WHEN … 這種寫法會先撞到這些字）
            if (in_array(strtolower($token), [
                'distinct', 'null', 'case', 'when', 'then', 'else', 'end',
                'is', 'not', 'and', 'or',
            ], true)) {
                continue;
            }

            return $this->columnName($token);
        }

        return '*';
    }

    /** 去掉資料表別名與欄位別名：p.plan_qty => plan_qty */
    private function columnName(string $expr): string
    {
        $expr = trim(preg_replace('/\s+as\s+\w+$/i', '', trim($expr)));
        $dot  = strrchr($expr, '.');

        return strtolower($dot ? substr($dot, 1) : $expr);
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
