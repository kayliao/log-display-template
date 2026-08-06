<?php
/**
 * 機台詳細資料（放大鏡點下去看到的內容）。
 *
 * 回傳的是「多段區塊」結構，前端 App.modal.detail 直接照著渲染：
 *   { title, sections: [ {type:'fields'|'table', title, ...}, ... ] }
 *
 * 要讓彈窗多一段內容，改 Service 就好，前端一行都不用動——
 * 這是把「彈窗裡有幾張表」這件事交給後端決定的用意。
 */

require __DIR__ . '/../_boot.php';

use App\Core\Auth;
use App\Core\AppException;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Machine\MachineService;

Auth::requirePermission('monitor.status');

$machineId = Request::str('machine_id');

if ($machineId === '') {
    throw new AppException('請指定機台編號。', 422);
}

Response::ok((new MachineService())->detail($machineId));
