<?php
/**
 * 應用程式基本設定。
 *
 * 本機/現場差異請寫在 config/local.php（不進版控），會覆蓋這裡的值。
 */

return [
    // 系統顯示名稱（header 左上、瀏覽器標題用）
    'name' => '廠務機台監看系統',

    // 版本號，會附加在靜態檔 URL 後面做為 cache buster
    // 每次改前端檔案請把這個數字往上加，現場瀏覽器才會重新抓。
    'version' => '1.0.0',

    // debug = true 時，錯誤會直接顯示在畫面上。正式環境務必關掉。
    'debug' => false,

    'timezone' => 'Asia/Taipei',
    'locale'   => 'zh-TW',

    /**
     * base_url：本系統在網站中的路徑前綴。
     *
     * - DocumentRoot 指到 public/            => ''
     * - DocumentRoot 指到專案根目錄          => ''（根目錄 index.php 會轉發）
     * - 專案放在子目錄，例如 /machine/       => '/machine'
     *
     * 留 null 表示自動偵測（大多數情況可用）。
     */
    'base_url' => null,

    'session' => [
        'name' => 'FACTORY_SESSID',

        // 閒置多久自動登出（秒）。header 的倒數計時器讀這個值。
        'lifetime' => 30 * 60,

        // 剩下多少秒時跳出「即將登出」提醒視窗
        'warn_before' => 3 * 60,

        // 使用者有操作時是否自動延長（前端 heartbeat）
        'renew_on_activity' => true,
    ],

    'log' => [
        'path'  => BASE_PATH . '/storage/logs',
        // debug | info | warning | error
        'level' => 'info',
        // 保留天數，超過的自動刪除（由 Logger 在寫入時順手處理）
        'keep_days' => 30,
    ],

    /**
     * 對外 API（public/service/v1）的存取控制。
     * 這一區是給「別的系統」呼叫用的，跟前端頁面的 Session 驗證完全分開。
     */
    'service_api' => [
        // 呼叫端 => 金鑰。正式環境請放在 config/local.php。
        'keys' => [
            // 'MES'  => '請改成實際金鑰',
            // 'SCADA' => '請改成實際金鑰',
        ],

        // IP 白名單（空陣列 = 不限制）。支援 CIDR，例如 '10.20.0.0/16'
        'ip_whitelist' => [],

        // 是否把每一筆對外 API 請求寫進 log
        'log_requests' => true,
    ],

    /**
     * 測試帳號。
     *
     * ⚠ 只在「還沒接上公司既有登入邏輯」的期間使用。
     *   接上之後請把這一整段刪掉，或在 config/local.php 覆寫成空陣列。
     *   帳號 => [password, name, role, dept]
     */
    'demo_users' => [
        'admin' => ['password' => 'admin', 'name' => '系統管理員', 'role' => 'ADMIN',    'dept' => '資訊課'],
        'e001'  => ['password' => 'e001',  'name' => '王工程師',   'role' => 'ENGINEER', 'dept' => '製造課'],
        'v001'  => ['password' => 'v001',  'name' => '陳課長',     'role' => 'VIEWER',   'dept' => '品保課'],
    ],

    /**
     * 查詢區間限制（天）。日期選擇器與後端都會套用同一份設定，
     * 避免使用者繞過前端直接打 API 撈爆資料庫。
     */
    'query_range' => [
        'machine_log' => 7,    // 機台 Log 最多查一週
        'report'      => 31,   // 一般報表最多查一個月
        'default'     => 31,
    ],
];
