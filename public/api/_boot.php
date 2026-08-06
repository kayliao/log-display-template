<?php
/**
 * 前端 API 的共同啟動檔。
 *
 * api/ 底下每一支端點的第一行都 require 這個檔案，
 * 它負責：宣告這是 API 入口（錯誤要輸出 JSON 而非 HTML）、啟動框架、檢查登入。
 *
 *   require __DIR__ . '/../_boot.php';
 *   Auth::requirePermission('monitor.status');
 *   ...
 *   Response::page($rows, $total, $page, $size);
 *
 * 這一層只管「是不是登入中」，個別權限由端點自己宣告，
 * 因為同一頁可能會打好幾支不同權限的 API。
 */

// 讓 ErrorHandler 知道要輸出 JSON
define('APP_API_ENTRY', true);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\AppException;

if (!Auth::check()) {
    // 前端 App.http 收到 401 會自動導回登入頁
    throw AppException::unauthenticated();
}
