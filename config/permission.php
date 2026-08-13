<?php
/**
 * 權限設定。
 *
 * 目前用設定檔實作，之後要改成讀公司既有的權限資料表時：
 *   1. 實作 App\Core\Permission\DbPermissionProvider（已備好骨架）
 *   2. 把下面的 'provider' 改成 'db'
 * 呼叫端（Auth::can() / Auth::requirePermission()）完全不用改。
 */

return [
    // 'config' = 讀本檔案；'db' = 讀資料庫
    'provider' => 'config',

    /**
     * 角色 => 權限碼清單。
     *
     * 權限碼支援萬用字元：
     *   '*'          全部權限
     *   'report.*'   report 開頭的全部權限
     */
    'roles' => [
        'ADMIN' => ['*'],

        'MANAGER' => [
            'monitor.*',
            'log.*',
            'report.*',
            'hydration.*',
        ],

        'ENGINEER' => [
            'monitor.view', 'monitor.map', 'monitor.status', 'monitor.import',
            'log.view', 'log.machine', 'log.alarm',
            'report.view', 'report.daily', 'report.shift',
            'report.schedule', 'report.schedule_import',
            'hydration.view', 'hydration.import',
        ],

        'OPERATOR' => [
            'monitor.view', 'monitor.map', 'monitor.status',
            'log.view', 'log.machine',

            // 現場人員可以查水化紀錄，但不給匯入（匯入會改到別人的資料）
            'hydration.view',
        ],

        'VIEWER' => [
            'monitor.view', 'monitor.map',
        ],
    ],

    /**
     * db provider 用的資料表對應。
     * 拿到公司既有的權限表結構後改這裡即可。
     */
    'db' => [
        'connection'  => 'pg',
        'table'       => 'sys_user_permission',
        'user_column' => 'emp_no',
        'perm_column' => 'perm_code',
        // 若權限是掛在角色上，改用這組（設了就以這組為準）
        'role_table'  => null,   // 'sys_user_role'
        'role_column' => null,   // 'role_code'
    ],

    /**
     * 開發模式：登入後強制套用的角色。
     * 只在 config/local.php 覆寫使用，正式環境必須是 null。
     */
    'force_role' => null,
];
