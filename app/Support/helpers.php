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
     *
     * 第三個參數是「條件列名稱」。一頁只有一排條件列時不用給：
     *
     *     old('keyword')                  // ?keyword=M-101
     *
     * 一頁有兩排以上條件列時（例如權限管理頁的帳號清單與程式清單），
     * 兩排都有 keyword，不分開的話重新整理後同一個字會同時填進兩排。
     * 給 scope，網址上就分成兩組，各自讀各自的：
     *
     *     old('keyword', '', 'account')   // ?account[keyword]=A123&prog[keyword]=報表
     *
     * 名稱要跟 filter_bar 的 scope 參數一致，前端才知道要寫回哪一組。
     */
    function old(string $key, $default = '', string $scope = '')
    {
        /**
         * 順手登記「這一格的預設值是什麼」，給條件列的「清除」用。
         *
         * 前端本來是把「頁面載入時畫面上的值」當成預設值，但那個值就是這支
         * 函式從網址上填進去的——帶著條件重新整理一次，按清除等於還原成
         * 網址上那組條件，看起來就像按了沒反應。真正的預設值只有這裡
         * 知道（就是 $default），登記下來由 filter_bar 印給前端。
         *
         * 按 scope 分開放，filter_bar 渲染時會把自己那一組拿走並清掉，
         * 所以彌窗表單裡的 old() 不會跟條件列的混在一起。
         */
        if (!isset($GLOBALS['__app_filter_defaults'])) {
            $GLOBALS['__app_filter_defaults'] = [];
        }

        $GLOBALS['__app_filter_defaults'][$scope][$key] = $default;

        $get  = $_GET;
        $post = $_POST;

        if ($scope !== '') {
            $get  = isset($_GET[$scope])  && is_array($_GET[$scope])  ? $_GET[$scope]  : [];
            $post = isset($_POST[$scope]) && is_array($_POST[$scope]) ? $_POST[$scope] : [];
        }

        return isset($get[$key]) ? $get[$key] : (isset($post[$key]) ? $post[$key] : $default);
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
