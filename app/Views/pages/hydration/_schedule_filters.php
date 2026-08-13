<?php
/**
 * 水化排程 —— 查詢條件欄位。
 *
 * 能查的就是表格上那幾欄：水化日期、乾片批號、封包日編碼、封包批號、第幾次水化，
 * 另外加一個現場最常用的勾選（只看還沒取號的）。
 *
 * 欄位的 name 跟後端 API 收的參數名一致，
 * 條件列是直接把整個表單序列化送出去的。
 */

use App\Core\View;
?>

<?php View::component('date_range', [
    'name'    => 'schedule_date',
    'label'   => '水化日期',
    'scope'   => 'report',
    'default' => 7,
]); ?>

<?php
/**
 * 乾片批號一次可以查很多筆。
 * 現場的用法是從水化排程表複製一整欄貼進來，所以逗號、換行、空白都當分隔符號，
 * 前端不切字串，原樣送給後端由 Request::multi() 處理。
 */
View::component('field', [
    'type'        => 'multi',
    'name'        => 'ppcup_lots',
    'label'       => '乾片批號',
    'hint'        => '可貼多筆',
    'placeholder' => "PPCUP-A2408-10001\n或一行一筆貼上",
    'help'        => '逗號、空白或換行都可以分隔；留空表示不限',
    'limit'       => 200,
    'value'       => old('ppcup_lots'),
    'width'       => 'grow',
]);
?>

<?php View::component('field', [
    'name'        => 'date_code',
    'label'       => '封包日編碼',
    'placeholder' => 'H0812',
    'value'       => old('date_code'),
    'width'       => 130,
]); ?>

<?php View::component('field', [
    'name'        => 'packet_lot',
    'label'       => '封包批號',
    'icon'        => 'search',
    'placeholder' => '可只填一段',
    'value'       => old('packet_lot'),
    'width'       => 200,
]); ?>

<?php View::component('field', [
    'type'    => 'select',
    'name'    => 'cycle_num',
    'label'   => '第幾次水化',
    'empty'   => '全部',
    'options' => ['1' => '第 1 次', '2' => '第 2 次', '3' => '第 3 次', '4' => '第 4 次', '5' => '第 5 次'],
    'value'   => old('cycle_num'),
    'width'   => 120,
]); ?>

<?php View::component('field', [
    'type'  => 'checkbox',
    'name'  => 'only_no_packet',
    'label' => '狀態',
    'text'  => '只看還沒取號的',
    'value' => old('only_no_packet'),
]); ?>
