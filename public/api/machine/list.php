<?php
/**
 * 機台狀態總表資料（分頁）。
 *
 * 同一支端點同時支援兩種輸出：
 *   一般查詢          => JSON 分頁結果
 *   ?export=csv       => CSV 全量下載
 * 這樣「畫面看到的」和「匯出的」保證是同一份 SQL、同一套條件，不會對不起來。
 */

require __DIR__ . '/../_boot.php';

use App\Core\Auth;
use App\Core\Db\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\TableQuery;
use App\Domain\Machine\MachineRepository;
use App\Domain\Machine\MachineService;
use App\Support\ColumnSet;

Auth::requirePermission('monitor.status');

$filters = [
    'area'    => Request::str('area'),
    'status'  => Request::str('status'),
    'keyword' => Request::str('keyword'),
];

// 可排序欄位白名單。跟頁面上的欄位定義一致，前端能點的後端就允許。
$sortable = [
    'machine_id', 'machine_name', 'model', 'area', 'status',
    'run_minutes', 'idle_minutes', 'down_minutes',
    'qty_ok', 'qty_ng', 'oee', 'last_report_time',
];

$service = new MachineService();

// --- CSV 匯出 ---
if (Request::str('export') === 'csv') {
    [$sql, $bind] = (new MachineRepository())->statusQuery($filters);

    $columns = ColumnSet::make([
        ['key' => 'machine_id',       'title' => '機台編號'],
        ['key' => 'machine_name',     'title' => '機台名稱'],
        ['key' => 'model',            'title' => '機型'],
        ['key' => 'area',             'title' => '廠區'],
        ['key' => 'status_label',     'title' => '狀態'],
        ['title' => '今日時間分佈', 'children' => [
            ['key' => 'run_minutes',  'title' => '運轉'],
            ['key' => 'idle_minutes', 'title' => '待機'],
            ['key' => 'down_minutes', 'title' => '停機'],
        ]],
        ['key' => 'qty_ok',           'title' => '良品'],
        ['key' => 'qty_ng',           'title' => '不良'],
        ['key' => 'oee',              'title' => '稼動率'],
        ['key' => 'last_report_time', 'title' => '最後回報'],
    ])->exportColumns();

    $rows = Db::pg()->select($sql . ' ORDER BY m.machine_id', $bind);
    foreach ($rows as $i => $row) {
        $rows[$i]['status_label'] = MachineService::statusLabel($row['status'] ?? '');
    }

    Response::csv('機台狀態_' . date('Ymd_His'), $columns, $rows);
}

// --- 一般分頁查詢 ---
$query  = TableQuery::fromRequest($sortable, 'machine_id', 'asc');
$result = $service->statusTable($filters, $query);

$query->respond($result);
