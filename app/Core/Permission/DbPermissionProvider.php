<?php

namespace App\Core\Permission;

use App\Core\Config;
use App\Core\Db\Db;
use App\Core\Logger;

/**
 * 從資料庫讀取權限。
 *
 * ⚠ 尚未接上實際資料表。
 * 拿到公司既有的使用者/角色/權限表結構後：
 *   1. 調整 config/permission.php 的 'db' 區塊（資料表與欄位名稱）
 *   2. 若 SQL 結構跟這裡假設的不同，改下面兩個 method 的 SQL
 *   3. 把 config/permission.php 的 'provider' 改成 'db'
 * 其他地方一行都不用動。
 *
 * 目前的假設是二選一：
 *   A. 權限直接掛在使用者身上   => sys_user_permission(emp_no, perm_code)
 *   B. 權限掛在角色上           => sys_user_role(emp_no, role_code) 對應 config 的 roles
 */
class DbPermissionProvider implements PermissionProvider
{
    public function permissionsFor(string $empNo, string $role): array
    {
        $cfg = Config::get('permission.db', []);

        try {
            // 情況 B：先查使用者的角色，再用 config 的角色權限表展開
            if (!empty($cfg['role_table'])) {
                return $this->byRole($empNo, $cfg);
            }

            // 情況 A：直接查使用者的權限碼
            return $this->byUser($empNo, $cfg);
        } catch (\Throwable $e) {
            // 權限查詢失敗時「拒絕全部」，不要因為 DB 掛掉就放行
            Logger::error('權限查詢失敗，本次請求視為無任何權限', [
                'emp_no' => $empNo,
                'error'  => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function byUser(string $empNo, array $cfg): array
    {
        $rows = Db::conn($cfg['connection'] ?? null)->select(
            sprintf(
                'SELECT %s AS perm_code FROM %s WHERE %s = :emp_no',
                $cfg['perm_column'] ?? 'perm_code',
                $cfg['table'] ?? 'sys_user_permission',
                $cfg['user_column'] ?? 'emp_no'
            ),
            ['emp_no' => $empNo]
        );

        return array_column($rows, 'perm_code');
    }

    private function byRole(string $empNo, array $cfg): array
    {
        $rows = Db::conn($cfg['connection'] ?? null)->select(
            sprintf(
                'SELECT %s AS role_code FROM %s WHERE %s = :emp_no',
                $cfg['role_column'] ?? 'role_code',
                $cfg['role_table'],
                $cfg['user_column'] ?? 'emp_no'
            ),
            ['emp_no' => $empNo]
        );

        $map    = Config::get('permission.roles', []);
        $result = [];

        foreach (array_column($rows, 'role_code') as $roleCode) {
            if (!empty($map[$roleCode])) {
                $result = array_merge($result, $map[$roleCode]);
            }
        }

        return array_values(array_unique($result));
    }
}
