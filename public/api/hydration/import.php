<?php
/**
 * 水化紀錄匯入 API。
 *
 * 一支端點三個動作：template / preview / commit（跟其他兩支匯入一樣）。
 *
 * 不一樣的地方在 commit：有問題的列不會擋住整批，
 * 能寫的先寫進去，寫不進去的包成一份「結果報告」回給前端用彈窗顯示。
 */

require __DIR__ . '/../_boot.php';

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Upload;
use App\Domain\Hydration\HydrationImportService;

Auth::requirePermission('hydration.import');

$action  = Request::str('action', 'preview');
$service = new HydrationImportService();

// --- 下載範本 -------------------------------------------------------------
if ($action === 'template') {
    $headers = [];
    $sample  = [];

    foreach (HydrationImportService::columns() as $key => $meta) {
        $headers[$key] = $meta['title'];
        $sample[$key]  = $meta['sample'] ?? '';
    }

    Response::csv('水化紀錄匯入範本', $headers, [$sample]);
}

// --- 確認匯入 -------------------------------------------------------------
if ($action === 'commit') {
    $token = Request::str('token');
    $path  = Upload::pathOf($token);

    $result = $service->commit($path);

    Upload::forget($token);

    $message = $result['failed'] === 0
        ? sprintf('匯入完成：新增 %d 筆、更新 %d 筆。', $result['insert'], $result['update'])
        : sprintf(
            '匯入完成：成功 %d 筆，另有 %d 筆沒有寫入（詳見結果視窗）。',
            $result['written'],
            $result['failed']
        );

    Response::ok($result, $message);
}

// --- 上傳並預覽 -----------------------------------------------------------
Upload::cleanup();

$file   = Upload::receive('file', ['csv', 'txt'], 5 * 1024 * 1024);
$result = $service->preview($file['path']);

$result['token'] = $file['token'];
$result['name']  = $file['name'];

Response::ok($result);
