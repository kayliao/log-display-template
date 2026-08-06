<?php

namespace App\Core\Db;

use App\Core\Config;
use App\Core\Logger;

/**
 * 自建連線（LegacyBridge 取不到時的後備方案）。
 *
 * 一般情況下這裡不會被用到——連線應該都是從既有的 db.php 拿的。
 * 它存在的意義是：db.php 還沒接上時開發能繼續、
 * 以及未來要連 db.php 沒有的第三個資料庫時有地方可以設定。
 */
class ConnectionFactory
{
    public static function make(string $name): Connection
    {
        $cfg = Config::get('database.connections.' . $name);

        if (!is_array($cfg)) {
            throw new \RuntimeException("資料庫連線設定不存在：{$name}");
        }

        $driver = $cfg['driver'] ?? 'pgsql';

        Logger::debug('使用 config/database.php 自建連線', ['connection' => $name, 'driver' => $driver]);

        if ($driver === 'oracle') {
            return self::makeOracle($name, $cfg);
        }

        return self::makePgsql($name, $cfg);
    }

    private static function makePgsql(string $name, array $cfg): Connection
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $cfg['host'] ?? '127.0.0.1',
            $cfg['port'] ?? 5432,
            $cfg['database'] ?? ''
        );

        $pdo = new \PDO($dsn, $cfg['username'] ?? '', $cfg['password'] ?? '', [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $conn = new PdoConnection($pdo, 'pgsql', $name);
        self::runInit($conn, $cfg);

        return $conn;
    }

    private static function makeOracle(string $name, array $cfg): Connection
    {
        // oci8 優先：Windows 環境下 pdo_oci 常常沒編進 PHP
        if (($cfg['ext'] ?? 'oci8') === 'oci8') {
            if (!function_exists('oci_connect')) {
                throw new \RuntimeException('PHP 未載入 oci8 擴充，無法建立 Oracle 連線。');
            }

            $handle = @oci_connect(
                $cfg['username'] ?? '',
                $cfg['password'] ?? '',
                $cfg['tns'] ?? '',
                $cfg['charset'] ?? 'AL32UTF8'
            );

            if ($handle === false) {
                $err = oci_error();
                throw new \RuntimeException('Oracle 連線失敗：' . ($err['message'] ?? '未知錯誤'));
            }

            $conn = new Oci8Connection($handle, $name);
        } else {
            $pdo = new \PDO(
                'oci:dbname=' . ($cfg['tns'] ?? '') . ';charset=' . ($cfg['charset'] ?? 'AL32UTF8'),
                $cfg['username'] ?? '',
                $cfg['password'] ?? '',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );

            $conn = new PdoConnection($pdo, 'oracle', $name);
        }

        self::runInit($conn, $cfg);

        return $conn;
    }

    /**
     * 連線後的初始化 SQL（設定日期格式、編碼等）。
     * 失敗只記 log 不中斷，這些設定不是致命的。
     */
    private static function runInit(Connection $conn, array $cfg): void
    {
        foreach ($cfg['init'] ?? [] as $sql) {
            try {
                $conn->execute($sql);
            } catch (\Throwable $e) {
                Logger::warning('連線初始化 SQL 執行失敗', ['sql' => $sql, 'error' => $e->getMessage()]);
            }
        }
    }
}
