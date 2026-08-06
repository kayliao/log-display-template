<?php

namespace App\Core\Db;

use App\Core\Config;
use App\Core\Logger;

/**
 * 各種連線實作的共同部分。
 *
 * 把「交易、插入、單筆查詢、慢查詢記錄」這些跟資料庫種類無關的邏輯集中在這，
 * 子類別只要實作真正跟 PDO / oci8 / pgsql 有關的兩個方法。
 */
abstract class BaseConnection implements Connection
{
    /** @var string 這條連線在 config 裡的名稱，寫 log 用 */
    protected $name;

    /** @var string 'pgsql' 或 'oracle' */
    protected $driver;

    /** @var int 交易巢狀層數 */
    protected $transactionLevel = 0;

    public function driver(): string
    {
        return $this->driver;
    }

    public function selectOne(string $sql, array $bind = []): ?array
    {
        $rows = $this->select($sql, $bind);

        return $rows ? $rows[0] : null;
    }

    public function scalar(string $sql, array $bind = [])
    {
        $row = $this->selectOne($sql, $bind);
        if ($row === null) {
            return null;
        }

        return reset($row);
    }

    public function insert(string $table, array $data): int
    {
        if ($data === []) {
            throw new \InvalidArgumentException('insert() 的資料不可為空陣列');
        }

        $columns = array_keys($data);
        $holders = array_map(static function ($c) {
            return ':' . $c;
        }, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $holders)
        );

        return $this->execute($sql, $data);
    }

    public function transaction(callable $callback)
    {
        $this->begin();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    /**
     * 只保留 SQL 裡真的有出現的參數。
     *
     * 這讓 Repository 可以無腦把整包條件丟進來，
     * 不用為了「這次沒用到某個條件」而去組不同的 bind 陣列。
     */
    protected function filterBind(string $sql, array $bind): array
    {
        $filtered = [];

        foreach ($bind as $key => $value) {
            $key = ltrim((string) $key, ':');
            // \b 對 :name 這種寫法不可靠，用負向前瞻確保後面不是英數字
            if (preg_match('/:' . preg_quote($key, '/') . '(?![a-zA-Z0-9_])/', $sql)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * 欄位名一律轉小寫。
     *
     * Oracle 預設回傳大寫欄位名、PostgreSQL 回傳小寫，
     * 不統一的話同一份前端樣板換個資料庫就全壞。
     */
    protected function normalizeRows(array $rows): array
    {
        foreach ($rows as $i => $row) {
            $rows[$i] = array_change_key_case($row, CASE_LOWER);
        }

        return $rows;
    }

    /**
     * 記錄查詢耗時。超過門檻就寫進 log，方便現場抓效能問題。
     */
    protected function logQuery(string $sql, array $bind, float $startedAt): void
    {
        $ms = (microtime(true) - $startedAt) * 1000;

        $slowMs = (int) Config::get('database.slow_query_ms', 2000);
        if ($slowMs > 0 && $ms >= $slowMs) {
            Logger::warning('慢查詢', [
                'conn' => $this->name,
                'ms'   => round($ms),
                'sql'  => $this->compactSql($sql),
                'bind' => $bind,
            ]);

            return;
        }

        if (Config::get('database.log_queries')) {
            Logger::debug('SQL', [
                'conn' => $this->name,
                'ms'   => round($ms),
                'sql'  => $this->compactSql($sql),
                'bind' => $bind,
            ]);
        }
    }

    private function compactSql(string $sql): string
    {
        return preg_replace('/\s+/', ' ', trim($sql));
    }

    /**
     * 把底層的錯誤包成統一的例外，訊息裡不要有連線資訊。
     */
    protected function fail(string $message, string $sql, array $bind): \RuntimeException
    {
        Logger::error('資料庫錯誤：' . $message, [
            'conn' => $this->name,
            'sql'  => $this->compactSql($sql),
            'bind' => $bind,
        ]);

        return new \RuntimeException('資料庫查詢失敗（' . $this->name . '）：' . $message);
    }
}
