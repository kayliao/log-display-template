<?php
/**
 * 排程達成率。
 *
 * 示範重點：達成率統整卡（achievement 元件）+ 查詢條件列 + 明細表 + 上傳匯入
 * 四個東西掛在同一組查詢條件上。
 *
 * 統整卡的初始內容是這裡（後端）算好直接畫出來的，
 * 所以一進頁面就看得到數字，不會先閃一下空卡片再跳出數字；
 * 之後按「查詢」才由前端重新跟 API 要（元件設定 auto = false）。
 */

require __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;
use App\Domain\Report\ScheduleImportService;
use App\Domain\Report\ScheduleService;

Auth::requirePermission('report.schedule');

$service = new ScheduleService();

/**
 * 預設條件：今天 + 水化排程。
 * 網址上有帶條件（例如從別的頁面連過來、或重新整理）就以網址為準，
 * 這樣統整卡的第一次畫面跟查詢條件列上顯示的條件一定一致。
 */
$filters = [
    'plan_date'     => Request::date('plan_date') ?: date('Y-m-d'),
    'schedule_code' => Request::str('schedule_code', 'HYD'),
    'category'      => Request::str('category'),
    'line_names'    => Request::multi('line_names', 100),
];

View::render('pages/report/schedule', [
    'title'         => '排程達成率',
    'columns'       => ScheduleService::columns(),
    'schedules'     => $service->scheduleOptions(),
    'categories'    => ScheduleService::categoryOptions(),
    'summary'       => $service->summary($filters),
    'filters'       => $filters,
    'importColumns' => ScheduleImportService::columns(),
    'canImport'     => can('report.schedule_import'),
]);
