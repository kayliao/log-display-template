<?php

namespace App\Core;

/**
 * 對外 API 的守門與記錄。
 *
 * public/service/v1/ 底下的端點是「給別的系統呼叫」的，
 * 跟前端頁面的 Session 驗證完全分開：
 *   - 驗證方式：X-Api-Key 標頭 + IP 白名單
 *   - 不使用 Session、不吃 Cookie
 *   - 每一筆呼叫都會記錄，方便日後追「這筆資料是誰塞的」
 *
 * 用法（端點第一段）：
 *   require .../bootstrap.php;
 *   $client = ServiceApi::authenticate();
 *   ...
 *   ServiceApi::success(['id' => 123]);
 */
class ServiceApi
{
    /** @var string|null 通過驗證的呼叫端代號 */
    private static $client;

    /** @var float */
    private static $startedAt;

    /**
     * 驗證呼叫端身分，回傳呼叫端代號。驗證失敗直接輸出 401/403 並結束。
     */
    public static function authenticate(): string
    {
        self::$startedAt = microtime(true);

        self::checkIp();

        $key  = self::apiKey();
        $keys = Config::get('app.service_api.keys', []);

        if ($key === '') {
            self::reject('缺少 X-Api-Key 標頭。', 401);
        }

        $client = null;
        foreach ($keys as $name => $expected) {
            // 用 hash_equals 比對，避免用回應時間猜出金鑰
            if (is_string($expected) && $expected !== '' && hash_equals($expected, $key)) {
                $client = (string) $name;
                break;
            }
        }

        if ($client === null) {
            self::reject('API 金鑰無效。', 401);
        }

        self::$client = $client;

        return $client;
    }

    /**
     * 成功回應，同時寫入呼叫記錄。
     */
    public static function success($data = null, string $message = ''): void
    {
        self::log(200, $message);
        Response::ok($data, $message);
    }

    /**
     * 失敗回應，同時寫入呼叫記錄。
     */
    public static function reject(string $message, int $status = 400, $data = null): void
    {
        self::log($status, $message);
        Response::fail($message, $status, 1, $data);
    }

    /**
     * 把「單筆」與「多筆 items」統一成一個陣列，順便擋掉空請求與超量。
     *
     *   {"ppcup_lot": "..."}              → [ {"ppcup_lot": "..."} ]
     *   {"items": [ {...}, {...} ]}       → [ {...}, {...} ]
     *   什麼都沒帶 / {"items": []}        → 直接回 422，不會往下跑
     *
     * 最後那一種要特別處理：Request::json() 在 body 空的、Content-Type 不是
     * json、或 JSON 壞掉時都回 []，若直接寫成 [$payload] 就會變成 [[]]——
     * 一筆「所有欄位都是空的」資料，count 是 1，空值檢查因此永遠擋不下來，
     * 要一路跑到 Service 層才被擋，機台收到的也不是「沒有資料」而是
     * 「某某欄位不可為空」。
     *
     * @param array  $payload         Request::json() 的結果
     * @param int    $max             單次筆數上限（各端點自己拿捏，取決於交易鎖多久）
     * @param string $emptyMessage    沒帶資料時要給機台看的話
     * @param string $tooManyMessage  超量時要給機台看的話，沒給就用通用句子
     */
    public static function items(array $payload, int $max, string $emptyMessage, string $tooManyMessage = ''): array
    {
        $items = isset($payload['items']) && is_array($payload['items'])
            ? $payload['items']
            : ($payload === [] ? [] : [$payload]);

        if ($items === []) {
            self::reject($emptyMessage, 422);
        }

        if (count($items) > $max) {
            self::reject(
                $tooManyMessage !== ''
                    ? $tooManyMessage
                    : '單次最多 ' . $max . ' 筆，請分批送出。',
                422
            );
        }

        return $items;
    }

    /**
     * 檢查必填欄位，缺少就直接回錯誤。
     *
     * @param array    $payload 傳入的資料
     * @param string[] $required 必填欄位名稱
     */
    public static function requireFields(array $payload, array $required): void
    {
        $missing = [];

        foreach ($required as $field) {
            if (!isset($payload[$field]) || $payload[$field] === '') {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            self::reject('缺少必填欄位：' . implode('、', $missing), 422, ['missing' => $missing]);
        }
    }

    /**
     * 只允許指定的 HTTP 方法（insert 類端點應該限定 POST）。
     */
    public static function requireMethod(string $method): void
    {
        if (Request::method() !== strtoupper($method)) {
            self::reject('本端點僅接受 ' . strtoupper($method) . ' 請求。', 405);
        }
    }

    private static function apiKey(): string
    {
        return trim(
            $_SERVER['HTTP_X_API_KEY']
            ?? $_SERVER['HTTP_X_APIKEY']
            ?? ($_GET['api_key'] ?? '')
        );
    }

    private static function checkIp(): void
    {
        $whitelist = Config::get('app.service_api.ip_whitelist', []);

        if (!is_array($whitelist) || $whitelist === []) {
            return; // 未設定就不限制
        }

        $ip = Request::ip();

        foreach ($whitelist as $rule) {
            if (self::ipMatches($ip, (string) $rule)) {
                return;
            }
        }

        Logger::warning('對外 API 遭 IP 白名單拒絕', ['ip' => $ip]);
        Response::fail('來源位址未被允許。', 403);
    }

    /**
     * 支援單一 IP 與 CIDR 網段（10.20.0.0/16）。
     */
    private static function ipMatches(string $ip, string $rule): bool
    {
        if (strpos($rule, '/') === false) {
            return $ip === $rule;
        }

        [$subnet, $bits] = explode('/', $rule, 2);

        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private static function log(int $status, string $message): void
    {
        if (!Config::get('app.service_api.log_requests', true)) {
            return;
        }

        Logger::apiRequest([
            'client'   => self::$client ?? '(未驗證)',
            'ip'       => Request::ip(),
            'method'   => Request::method(),
            'endpoint' => parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH),
            'status'   => $status,
            'ms'       => self::$startedAt ? round((microtime(true) - self::$startedAt) * 1000) : null,
            'message'  => $message,
        ]);
    }
}
