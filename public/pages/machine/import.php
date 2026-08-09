<?php
/**
 * 機台清單匯入。
 *
 * 示範重點：檔案上傳 → 後端驗證預覽 → 確認後才寫入的兩段式匯入。
 */

require __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\View;
use App\Domain\Machine\MachineImportService;

Auth::requirePermission('monitor.import');

View::render('pages/machine/import', [
    'title'   => '機台清單匯入',
    'columns' => MachineImportService::columns(),
]);
