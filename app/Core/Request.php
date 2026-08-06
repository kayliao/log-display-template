<?php

namespace App\Core;

/**
 * 請求資料讀取。
 *
 * 存在的理由：讓「取參數」這件事有唯一入口，順便統一處理
 * JSON body、型別轉換與日期區間驗證，避免每個 API 各寫一套。
 */
class Request
{
    /** @var array|null 已解析的 JSON body 快取 */
    private static $jsonBody;

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    public static function isAjax(): bool
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
            || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
    }

    public static function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    /**
     * 取參數，依序找 GET -> POST -> JSON body。
     */
    public static function input(string $key, $default = null)
    {
        if (isset($_GET[$key]))  return $_GET[$key];
        if (isset($_POST[$key])) return $_POST[$key];

        $json = self::json();

        return $json[$key] ?? $default;
    }

    public static function str(string $key, string $default = ''): string
    {
        $v = self::input($key, $default);

        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::input($key, $default);

        return is_numeric($v) ? (int) $v : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::input($key, null);
        if ($v === null) {
            return $default;
        }

        return in_array(strtolower((string) $v), ['1', 'true', 'y', 'yes', 'on'], true);
    }

    /**
     * 取陣列參數。支援 a[]=1&a[]=2 或 a=1,2 兩種寫法。
     */
    public static function arr(string $key, array $default = []): array
    {
        $v = self::input($key, null);

        if (is_array($v))  return array_values($v);
        if (is_string($v) && $v !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $v)), 'strlen'));
        }

        return $default;
    }

    /**
     * 取日期（YYYY-MM-DD），格式不合就回 null。
     */
    public static function date(string $key): ?string
    {
        $v = self::str($key);
        if ($v === '') {
            return null;
        }

        $d = \DateTime::createFromFormat('Y-m-d', substr($v, 0, 10));

        return ($d && $d->format('Y-m-d') === substr($v, 0, 10)) ? $d->format('Y-m-d') : null;
    }

    /**
     * 取日期區間並驗證跨度。
     *
     * 前端日期選擇器已經會擋，但使用者可以直接打 API，
     * 所以後端一定要再擋一次，否則一個 SQL 就能把資料庫拖垮。
     *
     * @param string $scope config/app.php 的 query_range 對應鍵
     * @return array{0:string,1:string} [開始日, 結束日]
     * @throws AppException 區間不合法時
     */
    public static function dateRange(string $startKey = 'start_date', string $endKey = 'end_date', string $scope = 'default'): array
    {
        $start = self::date($startKey);
        $end   = self::date($endKey);

        if ($start === null || $end === null) {
            throw new AppException('請選擇查詢的起訖日期。');
        }

        if ($start > $end) {
            throw new AppException('起始日期不可晚於結束日期。');
        }

        $maxDays = (int) Config::get('app.query_range.' . $scope, Config::get('app.query_range.default', 31));
        $days    = (int) ((strtotime($end) - strtotime($start)) / 86400) + 1;

        if ($maxDays > 0 && $days > $maxDays) {
            throw new AppException(sprintf('查詢區間最多 %d 天，目前選了 %d 天，請縮小範圍。', $maxDays, $days));
        }

        return [$start, $end];
    }

    /**
     * 解析 JSON request body（對外 API 常用）。
     */
    public static function json(): array
    {
        if (self::$jsonBody !== null) {
            return self::$jsonBody;
        }

        self::$jsonBody = [];

        $type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($type, 'application/json') !== false) {
            $raw     = file_get_contents('php://input');
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                self::$jsonBody = $decoded;
            }
        }

        return self::$jsonBody;
    }

    /**
     * 目前頁面的相對路徑，header 用它判斷哪個選單要標成 active。
     */
    public static function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $uri = parse_url($uri, PHP_URL_PATH) ?: '';

        $base = Url::base();
        if ($base !== '' && str_starts($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }

        return '/' . ltrim($uri, '/');
    }
}
