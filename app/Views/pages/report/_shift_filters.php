<?php
/**
 * 班別產量報表 —— 查詢條件欄位。
 *
 * 這一頁的欄位全部用 field 元件寫，不自己刻 HTML。
 * 跟 _status_filters.php 的手寫版比較一下就看得出差別：
 * label 的字級、必填星號、圖示輸入框的內距這些細節都由元件決定，
 * 每加一頁就少一次「這頁的欄位跟別頁長得不太一樣」的機會。
 */

use App\Core\View;
?>

<?php
View::component('date_range', [
    'name'    => 'shift_date',
    'label'   => '查詢區間',
    'scope'   => 'report',
    'default' => 7,
]);
?>

<?php View::component('field', [
    'type'    => 'select',
    'name'    => 'area',
    'label'   => '廠區',
    'empty'   => '全部',
    'options' => $areas,
    'value'   => old('area'),
]); ?>

<?php View::component('field', [
    'name'        => 'keyword',
    'label'       => '關鍵字',
    'icon'        => 'search',
    'placeholder' => '機台編號 / 名稱',
    'value'       => old('keyword'),
    'width'       => 'grow',
]); ?>
