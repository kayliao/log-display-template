<?php
/**
 * 對外 API 說明書。
 *
 * 給資訊人員看的一頁：public/service/v1/ 底下每一支端點的完整用法，
 * 內容來自 config/api_docs.php。旁邊可以勾選要匯出的端點，
 * 產生一份單頁 HTML 拿去給沒有本系統帳號的人（例如機台廠商工程師）。
 *
 * 這一頁只是「說明」，不會呼叫到任何端點，也不會碰資料庫。
 */

require __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\View;

Auth::requirePermission('dev.api_docs');

View::render('pages/dev/api_docs', [
    'title'       => '對外 API 說明書',
    'pageScripts' => ['app.apidoc.js'],
]);
