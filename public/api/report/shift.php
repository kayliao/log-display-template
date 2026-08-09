<?php
/**
 * 班別產量報表資料（分頁）。
 *
 * 欄位定義、排序白名單、CSV 標題全部來自 ShiftService::columns()，
 * 所以「畫面上的欄位」「後端允許排序的欄位」「匯出檔的欄位」永遠是同一份。
 * 三層表頭在匯出時會攤成「今日產量-白班-良品」，脫離畫面也看得懂。
 */

require __DIR__ . '/../_boot.php';

use App\Core\Auth;
use App\Core\Db\Db;
use App\Core\Request;
use App\Core\TableQuery;
use App\Core\Response;
use App\Domain\Report\ShiftRepository;
use App\Domain\Report\ShiftService;
use App\Support\ColumnSet;

Auth::requirePermission('report.shift');

[$start, $end] = Request::dateRange('shift_date_start', 'shift_date_end', 'report');

$filters = [
    'start_date' => $start,
    'end_date'   => $end,
    'area'       => Request::str('area'),
    'keyword'    => Request::str('keyword'),

    // 一次指定多台機器。逗號、換行、空白都可以分隔，上限 200 筆。
    'machine_ids' => Request::multi('machine_ids', 200),
];

$set = ColumnSet::make(ShiftService::columns());

// --- CSV 匯出 ---
if (Request::str('export') === 'csv') {
    [$sql, $bind] = (new ShiftRepository())->query($filters);

    Response::csv(
        '班別產量_' . date('Ymd_His'),
        $set->exportColumns(),
        Db::pg()->select($sql . ' ORDER BY s.machine_id', $bind)
    );
}

// --- 一般分頁查詢 ---
$query  = TableQuery::fromRequest($set->sortableKeys(), 'machine_id', 'asc');
$result = (new ShiftService())->table($filters, $query);

$query->respond($result);
