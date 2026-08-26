<?php
/**
 * 匯出對外 API 說明書（單頁 HTML）。
 *
 *   GET /api/dev/api_doc_export.php                        整份匯出
 *   GET /api/dev/api_doc_export.php?keys[]=packet_lot      只匯出這一支
 *
 * 說明書頁面左邊那個勾選表單就是打這裡（普通的 GET 表單，不是 ajax）。
 * 不帶 keys 等於全選 —— 網址直接貼給同事也拿得到完整的一份。
 *
 * 這支跟同目錄其他 API 不一樣，回的不是 JSON 而是一個下載檔，
 * 但驗證方式相同：要登入、要有 dev.api_docs 權限。
 * 匯出的內容不含任何金鑰，只有「請填你的金鑰」的位置。
 */

require __DIR__ . '/../_boot.php';

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Support\ApiDoc;

Auth::requirePermission('dev.api_docs');

$selected = ApiDoc::select(Request::arr('keys'));

$html = View::capture('exports/api_doc', [
    'meta' => [
        'title'       => (string) config('api_docs.title', '對外 API 介接說明'),
        'system'      => (string) config('app.name', ''),
        'server'      => ApiDoc::server(),
        'contact'     => (string) config('api_docs.contact', ''),
        'exported_at' => date('Y-m-d H:i'),
    ],
    'common'    => config('api_docs.common', []),
    'endpoints' => $selected,
]);

Response::download(ApiDoc::filename($selected), $html);
