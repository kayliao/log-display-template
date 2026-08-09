<?php
/**
 * 選單設定 —— 全系統唯一的功能清單來源。
 *
 * 這一份設定同時餵給三個地方，改一次三邊同步：
 *   1. header 的主選單 / 子選單下拉
 *   2. 首頁依權限產生的功能小卡（大標 = 第一層、卡片內連結 = 第二層）
 *   3. 權限檢查（Auth::requirePermission() 用的權限碼就是這裡的 perm）
 *
 * ⚠ 目前只有以下七頁實際存在：
 *      /pages/machine/map.php      完整範例：機台平面圖（含指北針）
 *      /pages/machine/status.php   完整範例：報表（含兩層表頭、放大鏡）
 *      /pages/machine/import.php   完整範例：CSV / TXT 上傳匯入
 *      /pages/log/machine.php      完整範例：分頁籤 + 日期區間限制
 *      /pages/report/shift.php     完整範例：三層表頭
 *      /pages/report/daily.php     產生器產出的骨架（尚未填實際 SQL）
 *      /pages/dev/components.php   共用元件目錄（開發參考用，上線前可刪）
 *
 *   其餘項目是「選單長什麼樣子」的範例，點了會是 404。
 *   用 tools\new-page.ps1 補上對應頁面，或直接把用不到的項目刪掉。
 *
 * 欄位說明：
 *   key      唯一代號，也是預設的權限碼前綴
 *   title    顯示名稱
 *   icon     Bootstrap Icons 類別名（不含 bi- 前綴）
 *   perm     需要的權限碼；null = 登入即可看
 *   url      連結位置。以 / 開頭會自動補上 base_url
 *   legacy   true = 這是尚未搬遷的舊頁面，選單上會標示一個小記號
 *   note     程式說明，會顯示在 header 的「程式說明」裡
 *   children 子項目
 *   card     false = 不出現在首頁小卡（但仍出現在主選單）
 */

return [
    [
        'key'   => 'monitor',
        'title' => '機台監看',
        'icon'  => 'display',
        'perm'  => 'monitor.view',
        'children' => [
            [
                'key'   => 'monitor.map',
                'title' => '廠內機台平面圖',
                'icon'  => 'grid-3x3',
                'perm'  => 'monitor.map',
                'url'   => '/pages/machine/map.php',
                'note'  => '以廠區座標顯示機台位置與即時狀態，顏色代表運轉狀態，點擊機台可查看詳細資訊。',
            ],
            [
                'key'   => 'monitor.status',
                'title' => '機台狀態總表',
                'icon'  => 'list-check',
                'perm'  => 'monitor.status',
                'url'   => '/pages/machine/status.php',
                'note'  => '列出所有機台目前狀態、稼動率與最後回報時間。點放大鏡可展開該機台的即時明細。',
            ],
            [
                'key'   => 'monitor.import',
                'title' => '機台清單匯入',
                'icon'  => 'cloud-arrow-up',
                'perm'  => 'monitor.import',
                'url'   => '/pages/machine/import.php',
                'note'  => '用 CSV 或 TXT 批次新增／更新機台主檔。上傳後會先檢查並列出問題，'
                         . '確認沒問題才寫入資料庫，不會匯到一半停住。以機台編號比對，不會刪除資料。',
            ],
        ],
    ],

    [
        'key'   => 'log',
        'title' => 'Log 查詢',
        'icon'  => 'journal-text',
        'perm'  => 'log.view',
        'children' => [
            [
                'key'   => 'log.machine',
                'title' => '機台 Log 查詢',
                'icon'  => 'search',
                'perm'  => 'log.machine',
                'url'   => '/pages/log/machine.php',
                'note'  => '依機台與時間區間查詢原始 Log。查詢區間最長一週，資料量大時請縮小範圍。',
            ],
            [
                'key'   => 'log.alarm',
                'title' => '異常警報記錄',
                'icon'  => 'exclamation-triangle',
                'perm'  => 'log.alarm',
                'url'   => '/pages/log/alarm.php',
                'note'  => '查詢機台警報發生與解除記錄，可依警報代碼分類統計。',
            ],
        ],
    ],

    [
        'key'   => 'report',
        'title' => '報表',
        'icon'  => 'bar-chart-line',
        'perm'  => 'report.view',
        'children' => [
            [
                'key'   => 'report.daily',
                'title' => '每日生產日報',
                'icon'  => 'calendar-day',
                'perm'  => 'report.daily',
                'url'   => '/pages/report/daily.php',
                'note'  => '每日各機台產量、稼動率與停機時間彙總。查詢區間最長一個月。',
            ],
            [
                'key'   => 'report.shift',
                'title' => '班別產量報表',
                'icon'  => 'layers',
                'perm'  => 'report.shift',
                'url'   => '/pages/report/shift.php',
                'note'  => '白班／夜班分開統計的產量與稼動率。這一頁是「三層表頭」的範例：'
                         . '今日產量 → 白班／夜班 → 良品／不良／稼動率。',
            ],
            [
                'key'    => 'report.legacy_yield',
                'title'  => '良率統計表（舊）',
                'icon'   => 'graph-up',
                'perm'   => 'report.yield',
                'url'    => '/legacy/yield_report.php',
                'legacy' => true,
                'note'   => '尚未改版的舊頁面，功能維持原樣。',
            ],
        ],
    ],

    /**
     * 開發參考。
     *
     * 給接手的人看的，不是給現場操作員看的，所以權限只開給 ADMIN。
     * 上線前不想留著就把這一段連同 public/pages/dev/ 一起刪掉，
     * 其他功能不會受影響。
     */
    [
        'key'   => 'dev',
        'title' => '開發參考',
        'icon'  => 'braces',
        'perm'  => 'dev.view',
        'children' => [
            [
                'key'   => 'dev.components',
                'title' => '共用元件目錄',
                'icon'  => 'grid-1x2',
                'perm'  => 'dev.components',
                'url'   => '/pages/dev/components.php',
                'note'  => '所有共用元件的實際長相與用法，每一段都附上對應的 PHP 寫法，'
                         . '要用哪個元件直接複製過去改。',
            ],
        ],
    ],

    [
        'key'   => 'admin',
        'title' => '系統管理',
        'icon'  => 'gear',
        'perm'  => 'admin.view',
        'children' => [
            [
                'key'   => 'admin.announcement',
                'title' => '公告維護',
                'icon'  => 'megaphone',
                'perm'  => 'admin.announcement',
                'url'   => '/pages/admin/announcement.php',
                'note'  => '維護首頁提醒列的公告內容與有效期間。',
            ],
            [
                'key'   => 'admin.apilog',
                'title' => '對外 API 記錄',
                'icon'  => 'diagram-3',
                'perm'  => 'admin.apilog',
                'url'   => '/pages/admin/api_log.php',
                'note'  => '查看其他系統呼叫本系統 API 的記錄與結果，用於排查介接問題。',
            ],
        ],
    ],
];
