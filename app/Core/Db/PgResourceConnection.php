<?php

namespace App\Core\Db;

/**
 * pg_connect() 連線包裝。
 *
 * 舊的 db.php 有可能是用 pg_connect() 而不是 PDO 建立連線的，
 * 這個實作讓那種連線也能直接餵給新架構使用。
 *
 * pg_query_params 只支援 $1、$2 這種位置參數，
 * 所以這裡負責把 SQL 裡的 :name 轉成位置參數，
 * 讓 Repository 不管接到哪種連線都能用同一套具名參數寫法。
 */
class PgResourceConnection extends BaseConnection
{
    /** @var resource|object */
    private $conn;

    public function __construct($conn, string $name = 'pg')
    {
        if (!function_exists('pg_query_params')) {
            throw new \RuntimeException('PHP 未載入 pgsql 擴充，無法使用 PostgreSQL 連線。');
        }

        $this->conn   = $conn;
        $this->driver = 'pgsql';
        $this->name   = $name;
    }

    public function select(string $sql, array $bind = []): array
    {
        $result = $this->run($sql, $bind);

        // pg_fetch_all() 的第二個參數是 PHP 8.0 才有的，7.2 不能傳；
        // 所幸 7.2 的預設行為就是回傳關聯陣列，直接呼叫即可。
        // 另外 7.2 在沒有資料時回傳 false（8.0 起才回空陣列），所以要用 ?: 接住。
        $rows = pg_fetch_all($result);
        pg_free_result($result);

        return $this->normalizeRows($rows ?: []);
    }

    public function execute(string $sql, array $bind = []): int
    {
        $result = $this->run($sql, $bind);
        $count  = pg_affected_rows($result);
        pg_free_result($result);

        return (int) $count;
    }

    public function begin(): void
    {
        if ($this->transactionLevel === 0) {
            $this->command('BEGIN');
        }
        $this->transactionLevel++;
    }

    public function commit(): void
    {
        $this->transactionLevel = max(0, $this->transactionLevel - 1);

        if ($this->transactionLevel === 0) {
            $this->command('COMMIT');
        }
    }

    public function rollBack(): void
    {
        if ($this->transactionLevel > 0) {
            $this->command('ROLLBACK');
        }
        $this->transactionLevel = 0;
    }

    /** 執行不需要結果的指令，順手釋放 result 避免累積 */
    private function command(string $sql): void
    {
        $result = $this->run($sql, []);
        pg_free_result($result);
    }

    public function raw()
    {
        return $this->conn;
    }

    private function run(string $sql, array $bind)
    {
        $bind    = $this->filterBind($sql, $bind);
        $started = microtime(true);

        [$converted, $values] = $this->toPositional($sql, $bind);

        $result = @pg_query_params($this->conn, $converted, $values);

        if ($result === false) {
            throw $this->fail((string) pg_last_error($this->conn), $sql, $bind);
        }

        $this->logQuery($sql, $bind, $started);

        return $result;
    }

    /**
     * 把 :name 換成 $1、$2…，並產生對應順序的值陣列。
     * 同一個參數在 SQL 中出現多次時共用同一個位置編號。
     *
     * @return array{0:string, 1:array}
     */
    private function toPositional(string $sql, array $bind): array
    {
        $order  = [];
        $values = [];

        $converted = preg_replace_callback(
            '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
            static function ($m) use ($bind, &$order, &$values) {
                $key = $m[1];

                if (!array_key_exists($key, $bind)) {
                    return $m[0]; // 不是參數（例如 Postgres 的型別轉換 ::text）
                }

                if (!isset($order[$key])) {
                    $values[]     = $bind[$key];
                    $order[$key]  = count($values);
                }

                return '$' . $order[$key];
            },
            $sql
        );

        // pg 只吃字串與 null，布林要先轉成 t/f
        foreach ($values as $i => $v) {
            if (is_bool($v)) {
                $values[$i] = $v ? 't' : 'f';
            }
        }

        return [$converted, $values];
    }
}
