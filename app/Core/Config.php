<?php

namespace App\Core;

/**
 * 設定讀取器。
 *
 * 載入 config/ 底下每一個 .php，檔名即為第一層 key：
 *   config/app.php      => Config::get('app.name')
 *   config/database.php => Config::get('database.connections.pg.host')
 *
 * config/local.php 比較特別：它的內容會「深層合併」覆蓋到其他設定上，
 * 用來放各機器不同的值（連線密碼、debug 開關），且不進版控。
 */
class Config
{
    /** @var array */
    private static $items = [];

    private static $loaded = false;

    public static function load(string $dir): void
    {
        if (self::$loaded) {
            return;
        }

        foreach (glob($dir . '/*.php') as $file) {
            $name = basename($file, '.php');
            if ($name === 'local') {
                continue;
            }
            self::$items[$name] = require $file;
        }

        // local.php 最後套用，覆蓋前面的設定
        if (is_file($dir . '/local.php')) {
            $local = require $dir . '/local.php';
            if (is_array($local)) {
                self::$items = self::mergeDeep(self::$items, $local);
            }
        }

        self::$loaded = true;
    }

    /**
     * 用點號路徑取值：Config::get('app.session.lifetime', 1800)
     */
    public static function get(string $key, $default = null)
    {
        $value = self::$items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * 執行期覆寫（測試或特殊頁面用，不會寫回檔案）。
     */
    public static function set(string $key, $value): void
    {
        $segments = explode('.', $key);
        $ref      = &self::$items;

        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }

        $ref = $value;
    }

    public static function has(string $key): bool
    {
        return self::get($key, '__MISSING__') !== '__MISSING__';
    }

    private static function mergeDeep(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !self::isList($value)) {
                $base[$key] = self::mergeDeep($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /** 判斷是否為連續索引陣列（清單型設定直接整個取代，不合併） */
    private static function isList(array $arr): bool
    {
        return $arr === [] || array_keys($arr) === range(0, count($arr) - 1);
    }
}
