<?php

namespace App\Core\Permission;

use App\Core\Config;

/**
 * 從 config/permission.php 讀取角色權限。
 *
 * 適合權限規則單純、不常變動的情況。
 * 改權限要改檔案並重新佈署，這是刻意的取捨——現場改權限的頻率很低，
 * 但「權限規則寫在版控裡看得到歷史」對稽核比較有利。
 */
class ConfigPermissionProvider implements PermissionProvider
{
    public function permissionsFor(string $empNo, string $role): array
    {
        $roles = Config::get('permission.roles', []);

        return isset($roles[$role]) && is_array($roles[$role])
            ? $roles[$role]
            : [];
    }
}
