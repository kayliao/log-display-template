<?php
/**
 * 機台 Log 查詢。
 *
 * 示範重點：分頁籤 + 日期區間限制 + 放大鏡下鑽。
 */

require __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\View;
use App\Domain\Machine\MachineService;

Auth::requirePermission('log.machine');

$columns = [
    ['key' => 'log_time', 'title' => '發生時間', 'width' => 160, 'format' => 'datetime',
     // 放大鏡：看這筆記錄前後 30 分鐘發生了什麼
     'drill' => [
         'api'    => url('/api/log/context.php'),
         'params' => ['machine_id', 'log_time'],
     ]],

    ['key' => 'machine_id', 'title' => '機台', 'width' => 110,
     'drill' => [
         'api'    => url('/api/machine/detail.php'),
         'params' => ['machine_id'],
     ]],

    ['key' => 'event_code', 'title' => '事件代碼', 'width' => 110],
    ['key' => 'event_type', 'title' => '類型',     'width' => 90, 'align' => 'center'],
    ['key' => 'message',    'title' => '訊息內容'],
    ['key' => 'operator',   'title' => '操作人員', 'width' => 100],

    ['key' => 'duration_sec', 'title' => '持續(秒)', 'width' => 90, 'align' => 'right', 'format' => 'number',
     'tip' => '該事件從發生到解除的秒數，即時事件顯示為空白。'],
];

View::render('pages/log/machine', [
    'title'   => '機台 Log 查詢',
    'columns' => $columns,
    'areas'   => (new MachineService())->areas(),
]);
