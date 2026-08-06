<?php

namespace App\Core;

/**
 * 連結產生。
 *
 * 這個系統要能在兩種佈署方式下都跑得動：
 *   A. DocumentRoot 指到 public/        => 連結是 /pages/xxx.php
 *   B. DocumentRoot 指到專案根目錄       => 連結是 /public/pages/xxx.php
 *   C. 放在子目錄，例如 /machine/       => 連結是 /machine/pages/xxx.php
 *
 * 所有樣板都用 url() / asset() 產生連結，不要寫死路徑，
 * 之後 vhost 怎麼設定都不用改程式。
 */
class Url
{
    /** @var string|null */
    private static $base;

    /**
     * 系統根路徑前綴，結尾不含斜線。
     */
    public static function base(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }

        $configured = Config::get('app.base_url');
        if ($configured !== null) {
            return self::$base = rtrim($configured, '/');
        }

        // 自動偵測：由目前執行檔在磁碟上的位置，反推它在網址中的前綴
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';          // 例：/machine/public/pages/a.php
        $scriptFile = $_SERVER['SCRIPT_FILENAME'] ?? '';      // 例：D:/www/machine/public/pages/a.php

        $scriptFile = str_replace('\\', '/', $scriptFile);
        $publicPath = str_replace('\\', '/', PUBLIC_PATH);

        if ($scriptFile !== '' && strpos($scriptFile, $publicPath) === 0) {
            // 執行檔在 public/ 底下，把 public 之後的部分從網址尾巴切掉
            $tail = substr($scriptFile, strlen($publicPath));           // /pages/a.php
            $tail = '/' . ltrim($tail, '/');

            if (substr($scriptName, -strlen($tail)) === $tail) {
                return self::$base = rtrim(substr($scriptName, 0, -strlen($tail)), '/');
            }
        }

        return self::$base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    }

    /**
     * 系統內連結。傳入的路徑一律以 / 開頭，相對於 public/。
     */
    public static function to(string $path = ''): string
    {
        if ($path === '' || $path === '/') {
            return self::base() . '/';
        }

        if (str_starts($path, 'http://') || str_starts($path, 'https://')) {
            return $path;
        }

        return self::base() . '/' . ltrim($path, '/');
    }

    /**
     * 靜態檔連結，附版本號當 cache buster。
     * 改完前端檔案記得把 config/app.php 的 version 往上加。
     */
    public static function asset(string $path): string
    {
        $version = Config::get('app.version', '1');

        return self::to('/assets/' . ltrim($path, '/')) . '?v=' . rawurlencode($version);
    }

    /**
     * 判斷某個選單連結是否為「目前所在頁面」，header 標 active 用。
     */
    public static function isCurrent(?string $path): bool
    {
        if (!$path) {
            return false;
        }

        return rtrim(Request::path(), '/') === rtrim('/' . ltrim($path, '/'), '/');
    }
}
