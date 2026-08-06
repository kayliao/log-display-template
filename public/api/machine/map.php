<?php
/**
 * 廠內機台平面圖資料。
 *
 * 回傳每台機器的座標、尺寸、機型與狀態，
 * 前端 App.machineMap 依這些資料畫 SVG。
 */

require __DIR__ . '/../_boot.php';

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Machine\MachineService;

Auth::requirePermission('monitor.map');

$area = Request::str('area');

Response::ok((new MachineService())->mapData($area !== '' ? $area : null));
