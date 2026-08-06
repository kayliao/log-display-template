<?php
/**
 * Log 前後文（放大鏡點在「發生時間」欄位時使用）。
 *
 * 示範放大鏡可以帶多個參數：這裡同時帶了 machine_id 與 log_time，
 * 由欄位定義的 drill.params 指定要從該列帶哪些欄位。
 */

require __DIR__ . '/../_boot.php';

use App\Core\Auth;
use App\Core\AppException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Log\MachineLogService;

Auth::requirePermission('log.machine');

$machineId = Request::str('machine_id');
$logTime   = Request::str('log_time');

if ($machineId === '' || $logTime === '') {
    throw new AppException('缺少查詢參數。', 422);
}

Response::ok((new MachineLogService())->context($machineId, $logTime));
