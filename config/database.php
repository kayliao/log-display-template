<?php
/**
 * 資料庫設定。
 *
 * 這個系統同時要連 Oracle（舊資料）與 PostgreSQL（新資料），
 * 而且連線通常是由專案根目錄既有的 db.php 建立的。
 *
 * 因此連線有兩種取得方式：
 *
 *   1. legacy 模式（優先）：直接沿用根目錄 db.php 建立好的連線。
 *      Core\Db\LegacyBridge 會載入 db.php，自動辨識裡面產生的是
 *      PDO / oci8 resource / pgsql resource，包成統一介面。
 *      => 好處是連線帳密只有一個地方維護，舊頁面與新頁面用的是同一條連線。
 *
 *   2. native 模式：由本系統自己用 PDO 建立連線。
 *      => db.php 還沒接上、或要連 db.php 沒有的第三個資料庫時使用。
 *
 * 兩種模式可以並存：pg 走 legacy、oracle 走 native 也沒問題。
 */

return [
    // 未指定連線名稱時使用哪一條
    'default' => 'pg',

    /**
     * ------------------------------------------------------------------
     * 既有 db.php 橋接設定
     * ------------------------------------------------------------------
     * 拿到實際的 db.php 之後，只需要調整這一區，業務程式碼完全不用動。
     */
    'legacy' => [
        'enabled' => true,

        // 舊的連線檔位置
        'file' => BASE_PATH . '/db.php',

        /**
         * db.php 執行完之後，要從哪裡取得連線物件。
         * 三種寫法，由上往下嘗試：
         *
         *   'var'      => 'conn'          從 $conn 這個變數取
         *   'function' => 'getPgConn'     呼叫這個函式取得
         *   'auto'     => true            自動掃描 db.php 產生的所有變數，
         *                                 挑出第一個符合該 driver 的連線
         *
         * driver 只接受 'pgsql' 或 'oracle'，用來決定 SQL 方言（分頁語法等）。
         */
        'map' => [
            'pg' => [
                'driver' => 'pgsql',
                'auto'   => true,
                // 'var'      => 'pgConn',
                // 'function' => 'getPgConnection',
            ],
            'oracle' => [
                'driver' => 'oracle',
                'auto'   => true,
                // 'var'      => 'conn',
                // 'function' => 'getOracleConnection',
            ],
        ],
    ],

    /**
     * ------------------------------------------------------------------
     * 自建連線設定（legacy 取不到時的後備）
     * ------------------------------------------------------------------
     * 實際帳密請寫在 config/local.php，不要進版控。
     */
    'connections' => [

        'pg' => [
            'driver'   => 'pgsql',
            'host'     => '127.0.0.1',
            'port'     => 5432,
            'database' => 'factory',
            'username' => '',
            'password' => '',
            'charset'  => 'utf8',
            // 連線後要執行的初始化 SQL
            'init'     => [
                "SET client_encoding TO 'UTF8'",
            ],
        ],

        'oracle' => [
            'driver' => 'oracle',

            // 'oci8'（建議，Oracle 官方擴充）或 'pdo_oci'
            'ext'    => 'oci8',

            // oci8 用：EasyConnect 字串或 tnsnames 別名
            'tns'      => '//10.0.0.10:1521/ORCLPDB',
            'username' => '',
            'password' => '',
            'charset'  => 'AL32UTF8',

            /**
             * Oracle 版本，決定分頁 SQL 怎麼寫：
             *   '12c'（含以上）=> OFFSET n ROWS FETCH NEXT m ROWS ONLY
             *   '11g'（含以下）=> ROWNUM 巢狀子查詢
             */
            'version' => '12c',

            'init' => [
                "ALTER SESSION SET NLS_DATE_FORMAT = 'YYYY-MM-DD HH24:MI:SS'",
                "ALTER SESSION SET NLS_TIMESTAMP_FORMAT = 'YYYY-MM-DD HH24:MI:SS'",
            ],
        ],
    ],

    /**
     * 慢查詢門檻（毫秒）。超過就寫進 storage/logs，方便現場抓效能問題。
     * 設成 0 表示關閉。
     */
    'slow_query_ms' => 2000,

    // 是否把每一句 SQL 都記進 log（只在查問題時暫時打開，很吃硬碟）
    'log_queries' => false,
];
