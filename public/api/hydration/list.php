<?php
/**
 * 水化排程 —— 明細資料（分頁）。
 *
 * 欄位定義、排序白名單、CSV 標題全部來自 HydrationService::columns()，
 * 而那一份的 key 就是資料表 AQUA_SCHEDULE 的欄位名。
 */

require __DIR__ . '/../_boot.php';

use App\Core\Auth;
use App\Core\Db\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\TableQuery;
use App\Domain\Hydration\HydrationRepository;
use App\Domain\Hydration\HydrationService;
use App\Support\ColumnSet;

Auth::requirePermission('hydration.view');

// 區間上限吃 config/app.php 的 query_range.report（一個月），後端會再擋一次
[$start, $end] = Request::dateRange('schedule_date_start', 'schedule_date_end', 'report');

$filters = [
    'start_date'     => $start,
    'end_date'       => $end,

    // 現場從水化排程表複製一整欄乾片批號貼進來
    'ppcup_lots'     => Request::multi('ppcup_lots', 200),

    'date_code'      => Request::str('date_code'),
    'packet_lot'     => Request::str('packet_lot'),
    'cycle_num'      => Request::int('cycle_num'),
    'only_no_packet' => Request::bool('only_no_packet'),
];

$set = ColumnSet::make(HydrationService::columns());

// --- CSV 匯出 ---
if (Request::str('export') === 'csv') {
    [$sql, $bind] = (new HydrationRepository())->query($filters);

    Response::csv(
        '水化排程_' . date('Ymd_His'),
        $set->exportColumns(),
        Db::oracle()->select(
            $sql . ' ORDER BY s.aqua_schedule_date DESC, s.ppcup_lot, s.aqua_cycle_num',
            $bind
        )
    );
}

// --- 一般分頁查詢 ---
$query  = TableQuery::fromRequest($set->sortableKeys(), 'aqua_schedule_date', 'desc');
$result = (new HydrationService())->table($filters, $query);

$query->respond($result);
