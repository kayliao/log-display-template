<?php
/**
 * 排程達成率 —— 明細資料（分頁）。
 *
 * 欄位定義、排序白名單、CSV 標題全部來自 ScheduleService::columns()，
 * 所以「畫面上的欄位」「後端允許排序的欄位」「匯出檔的欄位」永遠是同一份。
 */

require __DIR__ . '/../_boot.php';

use App\Core\Auth;
use App\Core\Db\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\TableQuery;
use App\Domain\Report\ScheduleRepository;
use App\Domain\Report\ScheduleService;
use App\Support\ColumnSet;

Auth::requirePermission('report.schedule');

/**
 * 查詢條件。
 * 統整卡（schedule_summary.php）收的是同一組條件，
 * 兩邊才會是同一天、同一個排程的數字。
 */
$filters = [
    'plan_date'     => Request::date('plan_date') ?: date('Y-m-d'),
    'schedule_code' => Request::str('schedule_code'),
    'category'      => Request::str('category'),
    'keyword'       => Request::str('keyword'),
    'line_names'    => Request::multi('line_names', 100),
];

$set = ColumnSet::make(ScheduleService::columns());

// --- CSV 匯出 ---
if (Request::str('export') === 'csv') {
    [$sql, $bind] = (new ScheduleRepository())->query($filters);

    Response::csv(
        '排程達成率_' . $filters['plan_date'],
        $set->exportColumns(),
        Db::pg()->select($sql . ' ORDER BY p.sort_no, p.line_name', $bind)
    );
}

// --- 一般分頁查詢 ---
$query  = TableQuery::fromRequest($set->sortableKeys(), 'line_name', 'asc');
$result = (new ScheduleService())->table($filters, $query);

$query->respond($result);
