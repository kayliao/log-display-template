<?php

namespace App\Core\Db;

/**
 * 後端分頁 —— 吸收 Oracle 與 PostgreSQL 的語法差異。
 *
 * 報表資料動輒數十萬筆，一定要在資料庫端就分好頁再送到前端，
 * 不能整包撈回來讓 JavaScript 切。這個類別讓 Repository 只要寫
 * 一句沒有 LIMIT 的 SQL，分頁與排序就自動處理好，兩種資料庫共用同一份程式碼。
 *
 * 用法：
 *   $result = Paginator::run($conn, $sql, $bind, [
 *       'page'     => 1,
 *       'size'     => 50,
 *       'sort'     => 'log_time',
 *       'dir'      => 'desc',
 *       'sortable' => ['log_time', 'machine_id', 'event_code'],  // 白名單，必填
 *   ]);
 *   // => ['rows' => [...], 'total' => 1234, 'page' => 1, 'size' => 50, 'pages' => 25]
 */
class Paginator
{
    /** 單頁最大筆數。再大就不是「看報表」而是「匯出」了，請走 CSV。 */
    public const MAX_SIZE = 500;

    public static function run(Connection $conn, string $sql, array $bind, array $options = []): array
    {
        $page = max(1, (int) ($options['page'] ?? 1));
        $size = (int) ($options['size'] ?? 50);
        $size = max(1, min($size, self::MAX_SIZE));

        $sql = self::applySort($sql, $options);

        $total  = self::count($conn, $sql, $bind);
        $offset = ($page - 1) * $size;

        // 頁碼超過總頁數時（例如刪除資料後重新整理）退回最後一頁
        if ($total > 0 && $offset >= $total) {
            $page   = (int) ceil($total / $size);
            $offset = ($page - 1) * $size;
        }

        $rows = $total > 0
            ? $conn->select(self::applyLimit($conn, $sql, $size, $offset), $bind)
            : [];

        return [
            'rows'  => $rows,
            'total' => $total,
            'page'  => $page,
            'size'  => $size,
            'pages' => (int) ceil(max(1, $total) / $size),
        ];
    }

    /**
     * 計算總筆數。
     *
     * 把原始 SQL 包成子查詢，這樣不管原本多複雜（JOIN、GROUP BY、CTE）都算得對。
     * 代價是效能，若某張報表真的太慢，可以在 options 傳 count_sql 自己指定。
     */
    public static function count(Connection $conn, string $sql, array $bind, ?string $countSql = null): int
    {
        if ($countSql === null) {
            // 有 ORDER BY 的子查詢在計數時完全沒用，拿掉可以省不少時間
            $stripped = self::stripOrderBy($sql);
            $countSql = 'SELECT COUNT(*) AS cnt FROM (' . $stripped . ') pager_count';
        }

        return (int) $conn->scalar($countSql, $bind);
    }

    /**
     * 加上排序。欄位必須在白名單內，否則直接忽略——
     * 排序欄位是從前端傳來的，不能拼進 SQL 而不檢查。
     */
    private static function applySort(string $sql, array $options): string
    {
        $sort = trim((string) ($options['sort'] ?? ''));
        if ($sort === '') {
            return $sql;
        }

        $sortable = $options['sortable'] ?? [];
        if (!is_array($sortable) || !in_array($sort, $sortable, true)) {
            return $sql;
        }

        $dir = strtolower((string) ($options['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        // 白名單過了還是再擋一次不合法字元，雙重保險
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $sort)) {
            return $sql;
        }

        return self::stripOrderBy($sql) . ' ORDER BY ' . $sort . ' ' . $dir;
    }

    /**
     * 依資料庫方言加上分頁子句。
     *
     * 這裡的 LIMIT 值是本系統自己算出來的整數，不是使用者輸入，
     * 而且 Oracle 的 FETCH NEXT 也不接受繫結參數，所以直接內嵌整數是安全的。
     */
    private static function applyLimit(Connection $conn, string $sql, int $size, int $offset): string
    {
        if ($conn->driver() === 'oracle') {
            $version = (string) \App\Core\Config::get('database.connections.oracle.version', '12c');

            // 11g 以下沒有 OFFSET/FETCH，只能用 ROWNUM 兩層包裝
            if (self::isLegacyOracle($version)) {
                $end   = $offset + $size;
                $start = $offset;

                return "SELECT * FROM ("
                     . "  SELECT pager_inner.*, ROWNUM AS pager_rn FROM ({$sql}) pager_inner"
                     . "  WHERE ROWNUM <= {$end}"
                     . ") WHERE pager_rn > {$start}";
            }

            return $sql . " OFFSET {$offset} ROWS FETCH NEXT {$size} ROWS ONLY";
        }

        return $sql . " LIMIT {$size} OFFSET {$offset}";
    }

    private static function isLegacyOracle(string $version): bool
    {
        $num = (int) preg_replace('/\D/', '', $version);

        return $num > 0 && $num < 12;
    }

    /**
     * 移除最外層的 ORDER BY。
     *
     * 只處理「ORDER BY 之後沒有右括號」的情況，避免誤砍子查詢裡的排序。
     * 遇到判斷不了的複雜 SQL 就原樣保留，寧可多排一次也不要改壞語意。
     */
    private static function stripOrderBy(string $sql): string
    {
        $pos = strripos($sql, ' ORDER BY ');
        if ($pos === false) {
            return $sql;
        }

        $tail = substr($sql, $pos);
        if (strpos($tail, ')') !== false) {
            return $sql; // 這個 ORDER BY 在子查詢裡，不能動
        }

        return substr($sql, 0, $pos);
    }
}
