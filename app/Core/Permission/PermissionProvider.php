<?php

namespace App\Core\Permission;

/**
 * 權限來源介面。
 *
 * 現在用設定檔（ConfigPermissionProvider），
 * 之後接公司既有的權限資料表時換成 DbPermissionProvider，
 * Auth 與所有頁面完全不用改。
 */
interface PermissionProvider
{
    /**
     * 取得某位使用者擁有的權限碼清單。
     *
     * @param string $empNo 工號
     * @param string $role  角色代碼（若權限是掛在角色上）
     * @return string[] 例如 ['monitor.view', 'report.*']
     */
    public function permissionsFor(string $empNo, string $role): array;
}
