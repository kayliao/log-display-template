<?php
/**
 * 水化紀錄 —— 明細資料（分頁）。
 *
 * 欄位定義、排序白名單、CSV 標題全部來自 HydrationService::columns()。
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
[$start, $end] = Request::dateRange('hyd_date_start', 'hyd_date_end', 'report');

$filters = [
    'start_date'   => $start,
    'end_date'     => $end,

    // 現場從水化排程表複製一整欄乾片批號貼進來
    'dry_lot_nos'  => Request::multi('dry_lot_nos', 200),

    'hyd_day_code' => Request::str('hyd_day_code'),
    'pack_lot_no'  => Request::str('pack_lot_no'),
    'hyd_seq'      => Request::int('hyd_seq'),
    'only_open'    => Request::bool('only_open'),
    'only_no_pre'  => Request::bool('only_no_pre'),
];

$set = ColumnSet::make(HydrationService::columns());

// --- CSV 匯出 ---
if (Request::str('export') === 'csv') {
    [$sql, $bind] = (new HydrationRepository())->query($filters);

    Response::csv(
        '水化紀錄_' . date('Ymd_His'),
        $set->exportColumns(),
        Db::oracle()->select($sql . ' ORDER BY w.hyd_date DESC, w.dry_lot_no, w.hyd_seq', $bind)
    );
}

// --- 一般分頁查詢 ---
$query  = TableQuery::fromRequest($set->sortableKeys(), 'hyd_date', 'desc');
$result = (new HydrationService())->table($filters, $query);

$query->respond($result);
