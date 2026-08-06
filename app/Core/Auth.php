<?php

namespace App\Core;

use App\Core\Permission\ConfigPermissionProvider;
use App\Core\Permission\DbPermissionProvider;
use App\Core\Permission\PermissionProvider;

/**
 * 登入狀態與權限判斷。
 *
 * 這一層刻意只管「誰登入了」「他能不能做這件事」，
 * 不管「怎麼驗證帳號密碼」——那還是留在既有的 login.php。
 * login.php 驗證成功後呼叫 Auth::login() 把使用者放進 Session 即可。
 */
class Auth
{
    /** @var PermissionProvider|null */
    private static $provider;

    /** @var array|null 本次請求的權限快取 */
    private static $permissions;

    /**
     * 登入成功後呼叫。
     *
     * @param array $user 至少要有 emp_no（工號）、name（姓名）
     *                    可選：role（角色代碼）、dept（部門）、avatar
     */
    public static function login(array $user): void
    {
        Session::regenerate();

        $_SESSION['user'] = [
            'emp_no' => (string) ($user['emp_no'] ?? ''),
            'name'   => (string) ($user['name'] ?? ''),
            'role'   => (string) ($user['role'] ?? 'VIEWER'),
            'dept'   => (string) ($user['dept'] ?? ''),
            'avatar' => $user['avatar'] ?? null,
        ];

        Session::touch();
        self::$permissions = null;

        Logger::info('登入成功', ['emp_no' => $_SESSION['user']['emp_no']]);
    }

    public static function logout(): void
    {
        if (isset($_SESSION['user']['emp_no'])) {
            Logger::info('登出', ['emp_no' => $_SESSION['user']['emp_no']]);
        }

        Session::destroy();
        self::$permissions = null;
    }

    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function check(): bool
    {
        return isset($_SESSION['user']['emp_no']) && $_SESSION['user']['emp_no'] !== '';
    }

    public static function empNo(): string
    {
        return (string) ($_SESSION['user']['emp_no'] ?? '');
    }

    public static function role(): string
    {
        $forced = Config::get('permission.force_role');
        if ($forced) {
            return (string) $forced;
        }

        return (string) ($_SESSION['user']['role'] ?? '');
    }

    /**
     * 目前使用者擁有的全部權限碼。
     */
    public static function permissions(): array
    {
        if (self::$permissions !== null) {
            return self::$permissions;
        }

        if (!self::check()) {
            return self::$permissions = [];
        }

        return self::$permissions = self::provider()->permissionsFor(self::empNo(), self::role());
    }

    /**
     * 權限判斷。傳 null 或空字串代表「登入即可」。
     * 支援萬用字元：使用者擁有 'report.*' 時，can('report.daily') 為 true。
     */
    public static function can(?string $permission): bool
    {
        if (!self::check()) {
            return false;
        }

        if ($permission === null || $permission === '') {
            return true;
        }

        foreach (self::permissions() as $granted) {
            if ($granted === '*' || $granted === $permission) {
                return true;
            }

            // 'report.*' 比對 'report.daily'
            if (substr($granted, -2) === '.*'
                && str_starts($permission, substr($granted, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * 頁面與 API 的守門員。放在入口檔第二行。
     *
     *   Auth::requirePermission('report.daily');
     *
     * 未登入丟 401（頁面會被導回登入頁），已登入但沒權限丟 403。
     */
    public static function requirePermission(?string $permission = null): void
    {
        if (!self::check()) {
            throw AppException::unauthenticated();
        }

        if (!self::can($permission)) {
            Logger::warning('權限不足遭拒', [
                'emp_no' => self::empNo(),
                'need'   => $permission,
                'path'   => $_SERVER['REQUEST_URI'] ?? '',
            ]);

            throw AppException::forbidden();
        }
    }

    private static function provider(): PermissionProvider
    {
        if (self::$provider === null) {
            self::$provider = Config::get('permission.provider') === 'db'
                ? new DbPermissionProvider()
                : new ConfigPermissionProvider();
        }

        return self::$provider;
    }
}
