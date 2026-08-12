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
    'version' => '1.6.0',

    // debug = true 時，錯誤會直接顯示在畫面上。正式環境務必關掉。
    'debug' => false,

    /**
     * ⚠⚠ 示範模式 ⚠⚠
     *
     * true  = 完全不連資料庫，改用 app/Core/Db/DemoData.php 的假資料。
     *         模板剛下載下來就能看到報表、平面圖、彈窗的完整效果，
     *         也方便展示給同事或主管看，不用先把資料庫接好。
     *
     * false = 正常連線。
     *
     * 【接上真實資料庫後，這裡一定要改成 false。】
     * 開著的時候每一頁上方都會有一條黃色提示列，不會不小心忘記關。
     */
    'demo_mode' => true,

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
     * 廠內平面圖。
     */
    'map' => [
        /**
         * 北方相對於畫面正上方轉幾度（順時針為正）。
         *
         * 平面圖的格線是照現場地面標線畫的，而地面標線通常不會剛好對正北，
         * 所以指北針要能轉。量法：拿廠區配置圖或手機指南針，
         * 對著平面圖的「往上」方向量。
         *
         * 0~360 任意角度都可以，也接受負數與小數：
         *   23.5   北方偏右 23.5 度
         *   -15    北方偏左 15 度（跟填 345 是同一件事）
         *   137.4  北方指向右下
         */
        'north_offset' => 0,

        /**
         * 指北針顯示位置。
         *
         *   bar           放在平面圖上方的工具列裡（預設，不會壓到機台）
         *   top-right     疊在畫布右上角
         *   top-left / bottom-right / bottom-left
         *   none          不顯示
         *
         * 疊在角落的版本會蓋住那一區的機台，
         * 確定那個角落沒有機器再改成 top-right 這類。
         */
        'compass_position' => 'bar',

        // 指北針旁邊那行字，寫清楚這是哪一種北，現場才不會誤會
        'compass_label' => '廠區座標北',

        /**
         * 角度的寫法。
         *   signed   23.5° E / 15° W（偏東偏西，跟現場講法一致）
         *   bearing  337.5°（0~360 方位角，跟測量圖面一致）
         */
        'compass_angle_format' => 'signed',
    ],

    /**
     * 水化管理（/pages/hydration/wafer.php）。
     *
     * 封包批號 = 乾片批號去掉後 pack_trim 碼 + 水化日編號 + 當日順序（2 碼）
     *            DRY-A2408-10001 → DRY-A2408 + H0812 + 01 => DRY-A2408H081201
     */
    'hydration' => [
        // 乾片批號要去掉的尾碼長度
        'pack_trim' => 5,

        // 當日順序的步進值：01 → 04 → 07
        'pack_step' => 3,

        /**
         * 順序的進位規則。兩種都實作了，改這一個字就切換：
         *
         *   'block'    每一段只用 0 / 3 / 6 / 9，滿了就換下一段從 0 開始
         *              01 04 07 10 13 16 19 20 23 … 96 99 A0 A3 A6 A9 B0 …
         *              一天最多 143 組
         *
         *   'decimal'  兩碼當成數字直接加 3（十位數 0-9 之後接 A-Z）
         *              01 04 07 10 13 … 94 97 A0 A3 A6 A9 B2 B5 …
         *              一天最多 120 組
         *
         * ⚠ 兩種規則差在「A9 的下一個是 B0 還是 B2」以及「97 這個號碼存不存在」。
         *   規格上兩邊的例子各對一半，所以預設 block（A9 → B0），
         *   確認之後改這裡即可，其他程式都不用動。
         */
        'pack_seq_mode' => 'block',
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
