<?php

namespace App\Core;

/**
 * Session 管理與閒置逾時。
 *
 * header 上的「倒數登出」倒數的就是這裡的 lifetime。
 * 逾時判斷放在後端（前端倒數只是顯示用），
 * 否則使用者把瀏覽器分頁擺著不動，前端計時器一樣不準。
 */
class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $lifetime = (int) Config::get('app.session.lifetime', 1800);

        session_name(Config::get('app.session.name', 'FACTORY_SESSID'));

        self::setCookieParams();

        // 伺服器端的 session 檔比前端逾時再多留一段，避免剛好在邊界被砍掉
        ini_set('session.gc_maxlifetime', (string) ($lifetime + 300));

        session_start();

        self::enforceIdleTimeout($lifetime);
    }

    /**
     * 設定 Session Cookie 參數。
     *
     * PHP 7.3 才支援用陣列一次帶入（也才有 samesite），
     * 現場跑的是 7.2，所以要分兩種寫法。
     * 之後升級 PHP 時這個方法可以整個簡化掉。
     */
    private static function setCookieParams(): void
    {
        $path   = Url::base() ?: '/';
        $secure = !empty($_SERVER['HTTPS']);

        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params([
                'lifetime' => 0,        // 關掉瀏覽器就失效
                'path'     => $path,
                'httponly' => true,     // JS 讀不到，降低 XSS 風險
                'samesite' => 'Lax',
                'secure'   => $secure,
            ]);

            return;
        }

        // PHP 7.2：只能用位置參數，且不支援 samesite。
        // 這是內網系統，少了 samesite 影響有限；升級 PHP 後上面那段就會生效。
        session_set_cookie_params(0, $path, '', $secure, true);
    }

    /**
     * 閒置逾時檢查。超過就清掉 session，後續 Auth::user() 自然變成未登入。
     */
    private static function enforceIdleTimeout(int $lifetime): void
    {
        if (!isset($_SESSION['user'])) {
            return;
        }

        $last = (int) ($_SESSION['_last_activity'] ?? 0);

        if ($last > 0 && (time() - $last) > $lifetime) {
            Logger::info('Session 閒置逾時自動登出', ['emp_no' => $_SESSION['user']['emp_no'] ?? '']);
            self::destroy();

            return;
        }

        // heartbeat 之外的一般請求也算「有操作」
        self::touch();
    }

    /** 更新最後活動時間 */
    public static function touch(): void
    {
        $_SESSION['_last_activity'] = time();
    }

    /**
     * 距離自動登出還剩幾秒。前端倒數計時器用這個值校正。
     */
    public static function secondsLeft(): int
    {
        $lifetime = (int) Config::get('app.session.lifetime', 1800);
        $last     = (int) ($_SESSION['_last_activity'] ?? time());

        return max(0, $lifetime - (time() - $last));
    }

    public static function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * 一次性訊息（例如「儲存成功」），讀過就消失。
     */
    public static function flash(string $key, $value = null)
    {
        if ($value === null) {
            $v = $_SESSION['_flash'][$key] ?? null;
            unset($_SESSION['_flash'][$key]);

            return $v;
        }

        $_SESSION['_flash'][$key] = $value;

        return null;
    }

    public static function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }

        session_destroy();
    }

    /**
     * 換發 session id（登入成功後呼叫，防 session fixation）。
     */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
