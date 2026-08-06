<?php
/**
 * 機台 Log 的事件類型統計。
 *
 * 這一支沒有分頁（類型數量固定不多），
 * 但仍然回傳標準的分頁信封，前端表格元件才不用分兩種處理方式。
 */

require __DIR__ . '/../_boot.php';

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Log\MachineLogService;

Auth::requirePermission('log.machine');

[$start, $end] = Request::dateRange('log_date_start', 'log_date_end', 'machine_log');

$rows = (new MachineLogService())->typeSummary([
    'start_date'  => $start,
    'end_date'    => $end,
    'machine_ids' => Request::arr('machine_ids'),
    'event_type'  => Request::str('event_type'),
    'keyword'     => Request::str('keyword'),
]);

Response::page($rows, count($rows), 1, max(1, count($rows)));
