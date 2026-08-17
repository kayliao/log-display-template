<?php
/**
 * 首頁公告設定。
 *
 * 目前用設定檔實作，改公告要改這個檔案並重新佈署——這是刻意的取捨：
 * 公告不常改，但「改了什麼、誰改的」留在版控裡比較好交代。
 *
 * 之後公告要改成放資料表時：
 *   1. 依實際結構調整下面的 'db' 區塊（連線與資料表名稱）
 *   2. 把 'source' 改成 'db'
 * 呼叫端（public/index.php）與 announcement 元件完全不用改。
 *
 * 哪幾則「現在該顯示」是由 App\Domain\Announcement\AnnouncementService 判斷，
 * 兩種來源共用同一份規則。
 */

return [
    // 'config' = 讀本檔案的 items；'db' = 讀資料表
    'source' => 'config',

    /**
     * 公告清單。
     *
     *   level        info | warning | danger（決定顏色與圖示，其他值一律當 info）
     *   title        標題
     *   content      內容
     *   date         顯示在右側的日期，留空就不顯示
     *   start_date   從哪一天開始顯示（含當天），留空 = 不限
     *   end_date     顯示到哪一天為止（含當天），留空 = 不限
     *   target_role  只給哪個角色看，角色代碼同 config/permission.php，留空 = 所有人
     *
     * 排序不用自己顧：danger → warning → info，同一級再照日期由新到舊。
     *
     * ⚠ 下面三則是範例，佈署前請改成實際公告。
     *   整個清單清空也沒關係，首頁就不會出現公告列。
     */
    'items' => [
        [
            'level'       => 'warning',
            'title'       => '系統維護通知',
            'content'     => '本週六 22:00 起進行資料庫例行維護，預計停機兩小時，屆時查詢功能將暫停使用。',
            'date'        => '2026-08-17',
            'start_date'  => '',
            'end_date'    => '',
            'target_role' => '',
        ],
        [
            'level'       => 'info',
            'title'       => '新功能上線',
            'content'     => '機台 Log 查詢新增「類型統計」頁籤，可快速查看各類事件的發生次數分佈。',
            'date'        => '2026-08-15',
            'start_date'  => '',
            'end_date'    => '',
            'target_role' => '',
        ],
        [
            'level'       => 'danger',
            'title'       => 'B 線異常提醒',
            'content'     => 'B 線 M-021 今日警報次數異常偏高，請設備課派員檢查主軸冷卻系統。',
            'date'        => '2026-08-16',
            'start_date'  => '',
            'end_date'    => '',
            'target_role' => '',
        ],

        /**
         * 有時效、又只給特定角色看的公告長這樣：
         *
         * [
         *     'level'       => 'info',
         *     'title'       => '排程匯入教育訓練',
         *     'content'     => '9/1 14:00 於二樓會議室，請負責匯入的同仁務必參加。',
         *     'date'        => '2026-08-20',
         *     'start_date'  => '2026-08-20',
         *     'end_date'    => '2026-09-01',
         *     'target_role' => 'ENGINEER',
         * ],
         */
    ],

    /**
     * source = 'db' 時才會用到。
     *
     * 假設的資料表：
     *   sys_announcement(id, level, title, content, start_date, end_date, target_role, created_at)
     * 結構跟這裡不同的話，改 App\Domain\Announcement\DbAnnouncementProvider 的 SQL。
     */
    'db' => [
        'connection' => 'pg',
        'table'      => 'sys_announcement',
    ],
];
