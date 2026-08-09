<?php
/**
 * 班別產量報表。
 *
 * 示範重點：三層表頭（大項 → 小項 → 更小項）。
 * 欄位定義只寫在 ShiftService::columns() 一個地方，
 * 表頭、排序白名單、CSV 匯出標題都由它產生。
 */

require __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\View;
use App\Domain\Machine\MachineService;
use App\Domain\Report\ShiftService;

Auth::requirePermission('report.shift');

View::render('pages/report/shift', [
    'title'   => '班別產量報表',
    'columns' => ShiftService::columns(),
    'areas'   => (new MachineService())->areas(),
]);
