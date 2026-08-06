<?php
/**
 * 機台 Log 查詢（分頁 / CSV 匯出）。
 */

require __DIR__ . '/../_boot.php';

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\TableQuery;
use App\Domain\Log\MachineLogService;

Auth::requirePermission('log.machine');

// 日期區間驗證：scope 對應 config/app.php 的 query_range.machine_log（一週）
// 前端日曆已經擋過一次，但直接打 API 也要擋，否則一句 SQL 就能拖垮資料庫
[$start, $end] = Request::dateRange('log_date_start', 'log_date_end', 'machine_log');

$filters = [
    'start_date'  => $start,
    'end_date'    => $end,
    'machine_ids' => Request::arr('machine_ids'),
    'event_type'  => Request::str('event_type'),
    'keyword'     => Request::str('keyword'),
];

$service = new MachineLogService();

// --- CSV 匯出 ---
if (Request::str('export') === 'csv') {
    Response::csv(
        '機台Log_' . $start . '_' . $end,
        [
            'log_time'     => '發生時間',
            'machine_id'   => '機台',
            'event_code'   => '事件代碼',
            'event_type'   => '類型',
            'message'      => '訊息內容',
            'operator'     => '操作人員',
            'duration_sec' => '持續秒數',
        ],
        $service->exportRows($filters)
    );
}

// --- 一般分頁查詢 ---
$sortable = ['log_time', 'machine_id', 'event_code', 'event_type', 'operator', 'duration_sec'];

$query  = TableQuery::fromRequest($sortable, 'log_time', 'desc');
$result = $service->search($filters, $query);

$query->respond($result);
