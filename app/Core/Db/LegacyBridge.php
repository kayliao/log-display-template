<?php

namespace App\Core\Db;

use App\Core\Config;
use App\Core\Logger;

/**
 * 既有 db.php 橋接。
 *
 * 這是整個資料層最關鍵的一塊：它讓新架構「沿用」而不是「取代」
 * 公司現有的連線檔，因此可以邊搬遷邊上線，不必先把 db.php 改掉。
 *
 * 運作方式：
 *   1. 在一個隔離的函式範圍內 require db.php
 *   2. 收集它產生的所有變數
 *   3. 依 config/database.php 的 legacy.map 找出對應的連線
 *      （指定變數名、指定函式名，或自動辨識型別）
 *   4. 依照實際型別包成 PdoConnection / Oci8Connection / PgResourceConnection
 *
 * 支援的 db.php 產物：
 *   - PDO 物件（pgsql 或 oci）
 *   - oci8 連線 resource（oci_connect / oci_pconnect 的回傳值）
 *   - pgsql 連線 resource（pg_connect 的回傳值）
 *   - 回傳上述任一種的函式
 */
class LegacyBridge
{
    /** @var array<string, mixed>|null db.php 執行後產生的變數 */
    private static $vars;

    /** @var bool db.php 是否已載入過 */
    private static $loaded = false;

    /**
     * 取得指定名稱的連線，取不到回傳 null（呼叫端會退回自建連線）。
     */
    public static function resolve(string $name): ?Connection
    {
        $map = Config::get('database.legacy.map.' . $name);
        if (!is_array($map)) {
            return null;
        }

        if (!self::load()) {
            return null;
        }

        $driver = $map['driver'] ?? 'pgsql';
        $handle = null;

        // (a) 指定變數名：$conn
        if (!empty($map['var']) && isset(self::$vars[$map['var']])) {
            $handle = self::$vars[$map['var']];
        }

        // (b) 指定函式名：getPgConnection()
        if ($handle === null && !empty($map['function']) && function_exists($map['function'])) {
            try {
                $handle = call_user_func($map['function']);
            } catch (\Throwable $e) {
                Logger::warning('呼叫 db.php 的連線函式失敗', [
                    'function' => $map['function'],
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        // (c) 自動辨識：掃描所有變數，挑第一個型別相符的
        if ($handle === null && !empty($map['auto'])) {
            $handle = self::sniff($driver);
        }

        if ($handle === null) {
            return null;
        }

        return self::wrap($handle, $driver, $name);
    }

    /**
     * 載入 db.php 並收集它產生的變數。
     * 用獨立函式範圍執行，避免污染呼叫端的變數。
     */
    private static function load(): bool
    {
        if (self::$loaded) {
            return self::$vars !== null;
        }
        self::$loaded = true;

        $file = Config::get('database.legacy.file');

        if (!$file || !is_file($file)) {
            Logger::debug('找不到既有的 db.php，改用 config/database.php 自建連線', ['file' => $file]);
            self::$vars = null;

            return false;
        }

        // 告訴 db.php「你正在被橋接層載入」。
        // 暫代版的 db.php 看到這個常數就不會反過來呼叫 Db::conn()，
        // 避免繞一圈回到自己身上。
        if (!defined('APP_LEGACY_BRIDGE_LOADING')) {
            define('APP_LEGACY_BRIDGE_LOADING', true);
        }

        try {
            self::$vars = (static function ($__file) {
                require_once $__file;

                $vars = get_defined_vars();
                unset($vars['__file']);

                return $vars;
            })($file);
        } catch (\Throwable $e) {
            // db.php 本身壞掉不應該讓整站掛掉，退回自建連線
            Logger::error('載入既有 db.php 失敗', ['file' => $file, 'error' => $e->getMessage()]);
            self::$vars = null;

            return false;
        }

        return true;
    }

    /**
     * 從 db.php 產生的變數中，找出符合指定 driver 的連線。
     */
    private static function sniff(string $driver)
    {
        foreach (self::$vars as $value) {
            if ($value instanceof \PDO) {
                $type = self::pdoDriver($value);
                if (($driver === 'oracle' && $type === 'oci') || ($driver === 'pgsql' && $type === 'pgsql')) {
                    return $value;
                }
                continue;
            }

            if ($driver === 'oracle' && self::isResourceOfType($value, 'oci8 connection')) {
                return $value;
            }

            if ($driver === 'pgsql' && self::isResourceOfType($value, 'pgsql link')) {
                return $value;
            }
        }

        return null;
    }

    /**
     * 依實際型別包成對應的 Connection 實作。
     */
    private static function wrap($handle, string $driver, string $name): ?Connection
    {
        if ($handle instanceof \PDO) {
            return new PdoConnection($handle, $driver, $name);
        }

        if (self::isResourceOfType($handle, 'oci8 connection')) {
            return new Oci8Connection($handle, $name);
        }

        if (self::isResourceOfType($handle, 'pgsql link')) {
            return new PgResourceConnection($handle, $name);
        }

        Logger::warning('db.php 提供的連線型別無法辨識', [
            'connection' => $name,
            'type'       => is_object($handle) ? get_class($handle) : gettype($handle),
        ]);

        return null;
    }

    /**
     * PHP 8.0 起 pg_connect / oci_connect 回傳的是物件而非 resource，
     * 這裡兩種情況都要判斷得出來。
     */
    private static function isResourceOfType($value, string $resourceType): bool
    {
        if (is_resource($value)) {
            return strpos(strtolower(get_resource_type($value)), strtolower($resourceType)) !== false;
        }

        if (is_object($value)) {
            $class = strtolower(get_class($value));
            if (strpos($resourceType, 'oci8') !== false) {
                return strpos($class, 'oci') !== false;
            }
            if (strpos($resourceType, 'pgsql') !== false) {
                return strpos($class, 'pgsql') !== false;
            }
        }

        return false;
    }

    private static function pdoDriver(\PDO $pdo): string
    {
        try {
            return (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * 診斷用：列出 db.php 到底產生了哪些東西。
     * 接線時如果自動辨識失敗，用這個看要在 map 裡填哪個變數名。
     */
    public static function inspect(): array
    {
        self::load();

        if (self::$vars === null) {
            return ['error' => '無法載入 db.php'];
        }

        $out = [];
        foreach (self::$vars as $key => $value) {
            if ($value instanceof \PDO) {
                $out['$' . $key] = 'PDO(' . self::pdoDriver($value) . ')';
            } elseif (is_resource($value)) {
                $out['$' . $key] = 'resource(' . get_resource_type($value) . ')';
            } elseif (is_object($value)) {
                $out['$' . $key] = get_class($value);
            } else {
                $out['$' . $key] = gettype($value);
            }
        }

        return $out;
    }
}
