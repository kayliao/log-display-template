<?php
/**
 * 機台狀態總表。
 *
 * 示範重點：
 *   - 欄位定義寫在 PHP（含大標小標、說明泡泡、放大鏡）
 *   - 資料由 api/machine/list.php 分頁提供，不在頁面裡查
 *   - 頁面本身只組設定，沒有 SQL、沒有 HTML
 */

require __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\View;
use App\Domain\Machine\MachineService;

Auth::requirePermission('monitor.status');

$columns = [
    ['key' => 'machine_id',   'title' => '機台編號', 'width' => 110,
     // 放大鏡：點下去用這一列的 machine_id 去打 detail API，結果顯示在彈窗
     'drill' => [
         'api'    => url('/api/machine/detail.php'),
         'params' => ['machine_id'],
     ]],

    ['key' => 'machine_name', 'title' => '機台名稱', 'width' => 160],
    ['key' => 'model',        'title' => '機型',     'width' => 120],
    ['key' => 'area',         'title' => '廠區',     'width' => 80, 'align' => 'center'],

    ['key' => 'status',       'title' => '狀態',     'width' => 90, 'align' => 'center',
     'format' => 'status'],

    // 大標底下掛小標
    ['title' => '今日時間分佈（分鐘）', 'children' => [
        ['key' => 'run_minutes',  'title' => '運轉', 'align' => 'right', 'format' => 'number'],
        ['key' => 'idle_minutes', 'title' => '待機', 'align' => 'right', 'format' => 'number'],
        ['key' => 'down_minutes', 'title' => '停機', 'align' => 'right', 'format' => 'number'],
    ]],

    ['title' => '今日產量', 'children' => [
        ['key' => 'qty_ok', 'title' => '良品', 'align' => 'right', 'format' => 'number'],
        ['key' => 'qty_ng', 'title' => '不良', 'align' => 'right', 'format' => 'number'],
    ]],

    ['key' => 'oee', 'title' => '稼動率', 'width' => 90, 'align' => 'right', 'format' => 'percent',
     'tip' => '運轉時間 ÷（運轉 + 待機 + 停機）× 100%，統計區間為今日 00:00 起。'],

    ['key' => 'last_report_time', 'title' => '最後回報', 'width' => 150, 'format' => 'datetime',
     'tip' => '機台最後一次上傳資料的時間。超過 10 分鐘沒有回報會以紅色顯示。'],
];

View::render('pages/machine/status', [
    'title'   => '機台狀態總表',
    'columns' => $columns,
    'areas'   => (new MachineService())->areas(),
]);
