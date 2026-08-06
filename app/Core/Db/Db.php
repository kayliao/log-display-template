<?php

namespace App\Core\Db;

use App\Core\Config;

/**
 * 連線管理員。
 *
 *   Db::conn()          預設連線
 *   Db::pg()            PostgreSQL
 *   Db::oracle()        Oracle
 *
 * 取得連線的順序：
 *   1. 先試 LegacyBridge —— 用專案根目錄既有的 db.php 建立好的連線
 *   2. 取不到才用 config/database.php 的設定自己建
 *
 * 這個順序很重要：只要 db.php 還在用，連線帳密就只有一個地方維護，
 * 舊頁面和新頁面也會共用同一條連線，不會出現「兩套設定不同步」的鬼問題。
 */
class Db
{
    /** @var array<string, Connection> 本次請求的連線快取 */
    private static $connections = [];

    public static function conn(?string $name = null): Connection
    {
        $name = $name ?: Config::get('database.default', 'pg');

        if (isset(self::$connections[$name])) {
            return self::$connections[$name];
        }

        // 1) 先試舊的 db.php
        if (Config::get('database.legacy.enabled')) {
            $conn = LegacyBridge::resolve($name);
            if ($conn !== null) {
                return self::$connections[$name] = $conn;
            }
        }

        // 2) 自己建
        return self::$connections[$name] = ConnectionFactory::make($name);
    }

    public static function pg(): Connection
    {
        return self::conn('pg');
    }

    public static function oracle(): Connection
    {
        return self::conn('oracle');
    }

    /**
     * 手動注入連線（測試或特殊情況用）。
     */
    public static function setConnection(string $name, Connection $conn): void
    {
        self::$connections[$name] = $conn;
    }

    /**
     * 已經建立的連線名稱，診斷頁用。
     */
    public static function active(): array
    {
        return array_keys(self::$connections);
    }
}
