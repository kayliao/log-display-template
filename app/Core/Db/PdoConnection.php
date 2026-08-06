<?php

namespace App\Core\Db;

/**
 * PDO 連線包裝（PostgreSQL 或 Oracle 皆可）。
 */
class PdoConnection extends BaseConnection
{
    /** @var \PDO */
    private $pdo;

    public function __construct(\PDO $pdo, string $driver, string $name = 'pdo')
    {
        $this->pdo    = $pdo;
        $this->driver = $driver;
        $this->name   = $name;

        // 連線可能是從舊 db.php 拿來的，屬性不一定設對，這裡統一補上
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function select(string $sql, array $bind = []): array
    {
        $bind    = $this->filterBind($sql, $bind);
        $started = microtime(true);

        try {
            $stmt = $this->pdo->prepare($sql);
            $this->bindValues($stmt, $bind);
            $stmt->execute();

            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            throw $this->fail($e->getMessage(), $sql, $bind);
        }

        $this->logQuery($sql, $bind, $started);

        return $this->normalizeRows($rows);
    }

    public function execute(string $sql, array $bind = []): int
    {
        $bind    = $this->filterBind($sql, $bind);
        $started = microtime(true);

        try {
            $stmt = $this->pdo->prepare($sql);
            $this->bindValues($stmt, $bind);
            $stmt->execute();

            $count = $stmt->rowCount();
        } catch (\PDOException $e) {
            throw $this->fail($e->getMessage(), $sql, $bind);
        }

        $this->logQuery($sql, $bind, $started);

        return $count;
    }

    public function begin(): void
    {
        if ($this->transactionLevel === 0) {
            $this->pdo->beginTransaction();
        }
        $this->transactionLevel++;
    }

    public function commit(): void
    {
        $this->transactionLevel = max(0, $this->transactionLevel - 1);

        if ($this->transactionLevel === 0 && $this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollBack(): void
    {
        $this->transactionLevel = 0;

        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function raw()
    {
        return $this->pdo;
    }

    /**
     * 明確指定型別繫結。
     * 整數若當成字串送出去，PostgreSQL 在某些比較運算會拒絕隱式轉型。
     */
    private function bindValues(\PDOStatement $stmt, array $bind): void
    {
        foreach ($bind as $key => $value) {
            if (is_int($value)) {
                $type = \PDO::PARAM_INT;
            } elseif (is_bool($value)) {
                $type = \PDO::PARAM_BOOL;
            } elseif ($value === null) {
                $type = \PDO::PARAM_NULL;
            } else {
                $type = \PDO::PARAM_STR;
            }

            $stmt->bindValue(':' . $key, $value, $type);
        }
    }
}
