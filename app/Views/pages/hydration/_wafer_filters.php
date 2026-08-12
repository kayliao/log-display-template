<?php
/**
 * 水化管理 —— 查詢條件欄位。
 *
 * 能查的就是表格上那幾欄：日期、乾片批號、水化日編號、封包批號、第幾次水化，
 * 另外加兩個現場最常用的勾選（還沒封包 / 還沒取號）。
 *
 * 欄位名稱要跟後端 API 收的參數名一致，
 * 條件列是直接把整個表單序列化送出去的。
 */

use App\Core\View;
?>

<?php View::component('date_range', [
    'name'    => 'hyd_date',
    'label'   => '日期',
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
    'name'        => 'dry_lot_nos',
    'label'       => '乾片批號',
    'hint'        => '可貼多筆',
    'placeholder' => "DRY-A2408-10001\n或一行一筆貼上",
    'help'        => '逗號、空白或換行都可以分隔；留空表示不限',
    'limit'       => 200,
    'value'       => old('dry_lot_nos'),
    'width'       => 'grow',
]);
?>

<?php View::component('field', [
    'name'        => 'hyd_day_code',
    'label'       => '水化日編號',
    'placeholder' => 'H0812',
    'value'       => old('hyd_day_code'),
    'width'       => 120,
]); ?>

<?php View::component('field', [
    'name'        => 'pack_lot_no',
    'label'       => '封包批號',
    'icon'        => 'search',
    'placeholder' => '正式或預配都找得到',
    'help'        => '正式與預配兩欄一起找',
    'value'       => old('pack_lot_no'),
    'width'       => 190,
]); ?>

<?php View::component('field', [
    'type'    => 'select',
    'name'    => 'hyd_seq',
    'label'   => '第幾次水化',
    'empty'   => '全部',
    'options' => ['1' => '第 1 次', '2' => '第 2 次', '3' => '第 3 次', '4' => '第 4 次', '5' => '第 5 次'],
    'value'   => old('hyd_seq'),
    'width'   => 120,
]); ?>

<?php View::component('field', [
    'type'  => 'checkbox',
    'name'  => 'only_open',
    'label' => '狀態',
    'text'  => '只看未封包',
    'value' => old('only_open'),
]); ?>

<?php
/**
 * 第二個勾選的 label 放一個全形空白，不是漏寫。
 * 條件列靠 label 的固定高度把每一欄對齊到同一條線，
 * label 給空字串的話這一欄會整個往上跑，跟隔壁差半行。
 */
View::component('field', [
    'type'  => 'checkbox',
    'name'  => 'only_no_pre',
    'label' => '　',
    'text'  => '只看未取號',
    'value' => old('only_no_pre'),
]);
?>
