<?php

namespace App\Core\Db;

/**
 * oci8 連線包裝。
 *
 * 舊系統的 Oracle 連線多半是用 oci_connect() 建的（pdo_oci 在 Windows 上
 * 常常沒編進去），所以這條路一定要支援，不能只做 PDO。
 *
 * 交易處理要特別注意：oci8 預設是 auto commit，
 * 進交易的方式是「執行時不要 commit」，所以這裡用一個旗標控制。
 */
class Oci8Connection extends BaseConnection
{
    /** @var resource|object */
    private $conn;

    /** @var bool 是否處於手動交易中 */
    private $inTransaction = false;

    /**
     * @var array 暫存最近一次的繫結值。
     * oci_bind_by_name 是傳參考，值必須在 oci_execute() 執行時仍然存在，
     * 所以要放在物件屬性上撐過 prepare() 的函式範圍。
     */
    private $boundValues = [];

    public function __construct($conn, string $name = 'oracle')
    {
        if (!function_exists('oci_parse')) {
            throw new \RuntimeException('PHP 未載入 oci8 擴充，無法使用 Oracle 連線。');
        }

        $this->conn   = $conn;
        $this->driver = 'oracle';
        $this->name   = $name;
    }

    public function select(string $sql, array $bind = []): array
    {
        $bind    = $this->filterBind($sql, $bind);
        $started = microtime(true);

        $stmt = $this->prepare($sql, $bind);

        if (!@oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
            throw $this->fail($this->lastError($stmt), $sql, $bind);
        }

        $rows = [];
        oci_fetch_all($stmt, $rows, 0, -1, OCI_FETCHSTATEMENT_BY_ROW | OCI_ASSOC | OCI_RETURN_NULLS);
        oci_free_statement($stmt);

        // 沒在交易中就立刻結束這個唯讀交易，避免長時間占著 undo
        if (!$this->inTransaction) {
            @oci_commit($this->conn);
        }

        $this->logQuery($sql, $bind, $started);

        return $this->normalizeRows($rows ?: []);
    }

    public function execute(string $sql, array $bind = []): int
    {
        $bind    = $this->filterBind($sql, $bind);
        $started = microtime(true);

        $stmt = $this->prepare($sql, $bind);

        // 交易中不自動 commit，交由 commit()/rollBack() 決定
        $mode = $this->inTransaction ? OCI_NO_AUTO_COMMIT : OCI_COMMIT_ON_SUCCESS;

        if (!@oci_execute($stmt, $mode)) {
            throw $this->fail($this->lastError($stmt), $sql, $bind);
        }

        $count = oci_num_rows($stmt);
        oci_free_statement($stmt);

        $this->logQuery($sql, $bind, $started);

        return (int) $count;
    }

    public function begin(): void
    {
        if ($this->transactionLevel === 0) {
            $this->inTransaction = true;
        }
        $this->transactionLevel++;
    }

    public function commit(): void
    {
        $this->transactionLevel = max(0, $this->transactionLevel - 1);

        if ($this->transactionLevel === 0 && $this->inTransaction) {
            if (!@oci_commit($this->conn)) {
                throw new \RuntimeException('Oracle commit 失敗：' . $this->lastError($this->conn));
            }
            $this->inTransaction = false;
        }
    }

    public function rollBack(): void
    {
        $this->transactionLevel = 0;

        if ($this->inTransaction) {
            @oci_rollback($this->conn);
            $this->inTransaction = false;
        }
    }

    public function raw()
    {
        return $this->conn;
    }

    /**
     * oci_bind_by_name 需要傳參考，所以值要先存在一個活著的陣列裡，
     * 不能直接在 foreach 裡綁區域變數（迴圈結束就失效）。
     */
    private function prepare(string $sql, array $bind)
    {
        $stmt = @oci_parse($this->conn, $sql);

        if ($stmt === false) {
            throw $this->fail($this->lastError($this->conn), $sql, $bind);
        }

        $holder = [];
        foreach ($bind as $key => $value) {
            $holder[$key] = $value;

            $length = -1;
            $type   = SQLT_CHR;

            if ($value === null) {
                $holder[$key] = null;
                $length       = -1;
            } elseif (is_int($value)) {
                $type = SQLT_INT;
            } elseif (is_bool($value)) {
                // Oracle 沒有 boolean 欄位型別，統一轉成 1/0
                $holder[$key] = $value ? 1 : 0;
                $type         = SQLT_INT;
            }

            oci_bind_by_name($stmt, ':' . $key, $holder[$key], $length, $type);
        }

        // 只留最近一次，讓它活過 oci_execute() 即可，不需要無限累積
        $this->boundValues = $holder;

        return $stmt;
    }

    private function lastError($handle): string
    {
        $err = @oci_error($handle);

        return is_array($err) ? ($err['message'] ?? '未知錯誤') : '未知錯誤';
    }
}
