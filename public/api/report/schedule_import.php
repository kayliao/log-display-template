<?php
/**
 * 排程與實績匯入 API。
 *
 * 一支端點三個動作，用 action 參數分（跟機台清單匯入同一套流程）：
 *   template  下載 CSV 範本（GET）
 *   preview   上傳檔案 → 解析驗證 → 回傳預覽與錯誤（POST，multipart）
 *   commit    確認匯入 → 真的寫進資料庫（POST，帶 preview 拿到的 token）
 */

require __DIR__ . '/../_boot.php';

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Upload;
use App\Domain\Report\ScheduleImportService;

Auth::requirePermission('report.schedule_import');

$action  = Request::str('action', 'preview');
$service = new ScheduleImportService();

// --- 下載範本 -------------------------------------------------------------
if ($action === 'template') {
    $headers = [];
    $sample  = [];

    foreach (ScheduleImportService::columns() as $key => $meta) {
        $headers[$key] = $meta['title'];
        $sample[$key]  = $meta['sample'] ?? '';
    }

    // 範本的表頭就是驗證用的那一份，不會出現「照範本填卻說欄位不對」。
    // format=txt 給同一份內容，只是副檔名不同：.txt 雙擊開的是記事本，
    // 填完存檔不會像 .csv 那樣被 Excel 改掉編碼。
    Response::delimited(Request::str('format', 'csv'), '排程實績匯入範本', $headers, [$sample]);
}

// --- 確認匯入 -------------------------------------------------------------
if ($action === 'commit') {
    $token = Request::str('token');
    $path  = Upload::pathOf($token);

    $result = $service->commit($path);

    // 寫完就把暫存檔刪掉，不要留著別人的排程資料在磁碟上
    Upload::forget($token);

    Response::ok($result, sprintf(
        '匯入完成：新增 %d 筆、更新 %d 筆。重新查詢即可看到最新的達成率。',
        $result['insert'],
        $result['update']
    ));
}

// --- 上傳並預覽 -----------------------------------------------------------
Upload::cleanup();

$file   = Upload::receive('file', ['csv', 'txt'], 5 * 1024 * 1024);
$result = $service->preview($file['path']);

$result['token'] = $file['token'];
$result['name']  = $file['name'];

Response::ok($result);
