<?php
/**
 * 廠內機台平面圖（分頁版）。
 *
 * 跟 map.php 的差別：
 *   map.php         左邊放廠區下拉與狀態統計，右邊一張圖，圖跟下拉連動
 *   map_floors.php  每一層樓一個頁籤，各查各的，彼此不連動
 *
 * 樓層清單是從資料庫查出來的，多一層樓不用改程式。
 */

require __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\View;
use App\Domain\Machine\MachineService;

Auth::requirePermission('monitor.map');

/**
 * 每一層樓的座標範圍。
 *
 * 各樓層的地面標線不一樣，所以軸的範圍是一層一設，不是全廠共用一份。
 * 沒有設定的樓層就用 default 那組。
 */
$axes = [
    '2F'      => ['x' => range('A', 'H'), 'y' => range(1, 8)],
    '4F'      => ['x' => range('I', 'L'), 'y' => range(1, 8)],
    'default' => ['x' => range('A', 'L'), 'y' => range(1, 10)],
];

View::render('pages/machine/map_floors', [
    'title'  => '廠內平面圖（分層）',
    'floors' => (new MachineService())->floors(),
    'axes'   => $axes,
]);
