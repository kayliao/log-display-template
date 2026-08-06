<?php
/**
 * 全域輔助函式。
 *
 * 只放「在 View 樣板裡會頻繁用到、寫成類別呼叫太囉唆」的東西。
 * 業務邏輯一律不要寫在這裡。
 */

use App\Core\Auth;
use App\Core\Config;
use App\Core\Url;

if (!function_exists('e')) {
    /**
     * HTML 逸出。所有輸出到畫面的變數都要包這個，沒有例外。
     */
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('config')) {
    function config(string $key, $default = null)
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('url')) {
    /**
     * 產生系統內連結：url('/pages/machine/status.php')
     */
    function url(string $path = ''): string
    {
        return Url::to($path);
    }
}

if (!function_exists('asset')) {
    /**
     * 產生靜態檔連結，並自動附上版本號避免瀏覽器快取舊檔。
     *   asset('css/app.css') => /assets/css/app.css?v=1.0.0
     */
    function asset(string $path): string
    {
        return Url::asset($path);
    }
}

if (!function_exists('user')) {
    /**
     * 目前登入者，未登入回傳 null。
     */
    function user(): ?array
    {
        return Auth::user();
    }
}

if (!function_exists('can')) {
    function can(?string $permission): bool
    {
        return Auth::can($permission);
    }
}

if (!function_exists('old')) {
    /**
     * 取回查詢條件，用於表單重新顯示使用者上次輸入的值。
     */
    function old(string $key, $default = '')
    {
        return isset($_GET[$key]) ? $_GET[$key] : (isset($_POST[$key]) ? $_POST[$key] : $default);
    }
}

if (!function_exists('json_out')) {
    /**
     * 直接輸出 JSON 並結束（給 API 入口用的捷徑）。
     */
    function json_out($data, int $status = 200): void
    {
        \App\Core\Response::json($data, $status);
    }
}

if (!function_exists('array_get')) {
    function array_get(array $array, string $key, $default = null)
    {
        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return $default;
            }
            $array = $array[$segment];
        }

        return $array;
    }
}

if (!function_exists('str_starts')) {
    function str_starts(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
