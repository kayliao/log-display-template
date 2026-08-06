<?php
/**
 * 廠內機台平面圖。
 */

require __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\View;
use App\Domain\Machine\MachineService;

Auth::requirePermission('monitor.map');

View::render('pages/machine/map', [
    'title' => '廠內機台平面圖',
    'areas' => (new MachineService())->areas(),
]);
