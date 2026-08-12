<?php
/**
 * 排程達成率 —— 統整卡資料。
 *
 * 回傳 achievement 元件吃的格式：
 *
 *   { "title": "...", "subtitle": "...", "footer": "...",
 *     "items": [ { "label": "白片", "plan": 12000, "actual": 11350, "color": "#0891b2" }, ... ] }
 *
 * 只給 plan / actual 兩個數字，達成率、合計、佔比由元件算——
 * 後端算一份、前端再算一份的話，四捨五入的位數遲早會對不起來。
 *
 * 合計是資料庫用 SUM 算的，不是把明細那一頁加起來（明細有分頁，
 * 前端手上只有當頁的資料，加起來會變成「這一頁的合計」）。
 */

require __DIR__ . '/../_boot.php';

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Report\ScheduleService;

Auth::requirePermission('report.schedule');

// 跟明細 API（schedule.php）收同一組條件，兩邊才會是同一天、同一個排程的數字
$filters = [
    'plan_date'     => Request::date('plan_date') ?: date('Y-m-d'),
    'schedule_code' => Request::str('schedule_code'),
    'category'      => Request::str('category'),
    'keyword'       => Request::str('keyword'),
    'line_names'    => Request::multi('line_names', 100),
];

Response::ok((new ScheduleService())->summary($filters));
