<?php
/**
 * ============================================================================
 *  資料庫連線檔（相容層）
 * ============================================================================
 *
 * ⚠ 這是「暫時的替代品」。
 *   公司現有的 db.php 拿到之後，直接覆蓋這個檔案即可，不需要改成什麼特定寫法——
 *   新架構的 App\Core\Db\LegacyBridge 會自動辨識它建立的連線並接手使用。
 *
 * ----------------------------------------------------------------------------
 *  它是怎麼運作的
 * ----------------------------------------------------------------------------
 *
 *  舊頁面：  require '../db.php';  然後直接用 $conn / $pdo
 *  新頁面：  Db::pg()->select(...)  透過 LegacyBridge 拿到同一條連線
 *
 *  兩邊用的是同一個連線設定，所以不會出現「兩套帳密不同步」的問題，
 *  也因此舊頁面可以一頁一頁慢慢搬，不必先把 db.php 改掉。
 *
 * ----------------------------------------------------------------------------
 *  接上實際的 db.php 之後要做的事
 * ----------------------------------------------------------------------------
 *
 *  1. 用瀏覽器開 /dev/db-check.php，看它列出 db.php 產生了哪些連線變數
 *  2. 依結果調整 config/database.php 的 legacy.map：
 *
 *       'pg' => [
 *           'driver' => 'pgsql',
 *           'var'    => 'pgConn',        // ← 填上實際的變數名
 *       ],
 *       'oracle' => [
 *           'driver' => 'oracle',
 *           'var'    => 'conn',
 *       ],
 *
 *     若 db.php 是用函式提供連線（例如 getConnection()），改填 'function' 即可。
 *
 *  3. 完成。所有 Repository 不需要任何修改。
 */

// 讓這個檔案被舊頁面直接 require 時也能運作
if (!defined('APP_BOOTSTRAPPED')) {
    require_once __DIR__ . '/app/bootstrap.php';
}

/**
 * 以下是「還沒接上實際 db.php」時的暫代連線，
 * 使用 config/database.php 的設定建立。
 *
 * 拿到公司的 db.php 之後，這一整段會被取代掉。
 */
$conn = null;   // Oracle
$pdo  = null;   // PostgreSQL

// 被 LegacyBridge 載入時不要建立連線——
// 那代表新架構正在問「這個檔案有沒有現成的連線可以用」，
// 暫代版沒有，讓它退回 config/database.php 自建即可。
if (!defined('APP_LEGACY_BRIDGE_LOADING')) {
    try {
        $pdo = \App\Core\Db\Db::pg()->raw();
    } catch (\Throwable $e) {
        \App\Core\Logger::warning('PostgreSQL 連線尚未設定完成', ['error' => $e->getMessage()]);
    }

    try {
        $conn = \App\Core\Db\Db::oracle()->raw();
    } catch (\Throwable $e) {
        \App\Core\Logger::warning('Oracle 連線尚未設定完成', ['error' => $e->getMessage()]);
    }
}
