<?php
/**
 * 全系統啟動檔。
 *
 * 三種入口（頁面 / 前端 API / 對外 API）的第一行都是 require 這個檔案。
 * 它負責：常數定義 -> 自動載入 -> 設定 -> 錯誤處理 -> Session。
 *
 * 這個檔案本身不做任何驗證，也不輸出任何內容。
 */

if (defined('APP_BOOTSTRAPPED')) {
    return;
}
define('APP_BOOTSTRAPPED', true);

// --- 路徑常數 -----------------------------------------------------------
define('BASE_PATH',    dirname(__DIR__));
define('APP_PATH',     BASE_PATH . '/app');
define('CONFIG_PATH',  BASE_PATH . '/config');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('PUBLIC_PATH',  BASE_PATH . '/public');
define('VIEW_PATH',    APP_PATH . '/Views');

// --- 自動載入 -----------------------------------------------------------
if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
} else {
    // vendor 不存在時的後備方案，讓系統仍能啟動
    require APP_PATH . '/autoload.php';
    require APP_PATH . '/Support/helpers.php';
}

use App\Core\Config;
use App\Core\ErrorHandler;
use App\Core\Session;

// --- 設定 ---------------------------------------------------------------
Config::load(CONFIG_PATH);

date_default_timezone_set(Config::get('app.timezone', 'Asia/Taipei'));
mb_internal_encoding('UTF-8');
setlocale(LC_ALL, 'zh_TW.UTF-8');

// --- 錯誤處理 -----------------------------------------------------------
// 一律不直接把錯誤吐給瀏覽器，改由 ErrorHandler 決定顯示成
// HTML 錯誤頁還是 JSON 錯誤回應，同時寫進 storage/logs。
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
ErrorHandler::register();

// --- Session ------------------------------------------------------------
// 對外 API（service/v1）不需要 Session，由該入口自行宣告 APP_NO_SESSION。
if (!defined('APP_NO_SESSION')) {
    Session::start();
}
