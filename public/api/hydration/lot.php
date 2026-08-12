<?php
/**
 * 水化紀錄 —— 乾片批號詳細（放大鏡彈窗）。
 *
 * 內容結構由後端決定（fields + table），前端 app.modal.js 照著畫。
 * 這一支的重點是告訴使用者「下一次匯入該填第幾次水化」——
 * 現場點進來多半就是為了搞懂自己為什麼被擋。
 */

require __DIR__ . '/../_boot.php';

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Hydration\HydrationService;

Auth::requirePermission('hydration.view');

$dryLotNo = strtoupper(trim(Request::str('dry_lot_no')));

if ($dryLotNo === '') {
    Response::fail('缺少乾片批號。', 422);
}

Response::ok((new HydrationService())->lotDetail($dryLotNo));
