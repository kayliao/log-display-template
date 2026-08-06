<?php

namespace App\Core\Db;

/**
 * 資料庫連線的統一介面。
 *
 * 底下可能是 PDO(pgsql)、PDO(oci)、oci8 resource 或 pg_connect resource，
 * 但業務程式碼（Repository）只會看到這個介面，
 * 所以「連線是從舊的 db.php 拿來的還是本系統自己建的」對上層完全透明。
 *
 * 參數繫結一律使用具名參數 :name。
 * PostgreSQL 與 Oracle 兩邊都支援這個寫法，SQL 可以少寫很多分歧。
 */
interface Connection
{
    /**
     * 查詢多筆。
     *
     * @return array<int, array<string, mixed>> 欄位名一律轉成小寫
     */
    public function select(string $sql, array $bind = []): array;

    /**
     * 查詢單筆，沒有資料回傳 null。
     */
    public function selectOne(string $sql, array $bind = []): ?array;

    /**
     * 查詢單一數值（COUNT、MAX 之類）。
     */
    public function scalar(string $sql, array $bind = []);

    /**
     * 執行 INSERT / UPDATE / DELETE，回傳影響筆數。
     */
    public function execute(string $sql, array $bind = []): int;

    /**
     * 便利方法：插入一筆資料。
     *
     * @param array<string, mixed> $data 欄位 => 值
     */
    public function insert(string $table, array $data): int;

    /**
     * 在交易中執行，callback 丟例外就自動 rollback。
     *
     * @param callable(Connection):mixed $callback
     * @return mixed callback 的回傳值
     */
    public function transaction(callable $callback);

    public function begin(): void;

    public function commit(): void;

    public function rollBack(): void;

    /**
     * SQL 方言：'pgsql' 或 'oracle'。Paginator 用它決定分頁語法。
     */
    public function driver(): string;

    /**
     * 取得底層連線物件（PDO 或 resource）。
     * 只在需要用到特定資料庫獨有功能時使用，一般情況不要碰。
     */
    public function raw();
}
