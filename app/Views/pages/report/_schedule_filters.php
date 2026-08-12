<?php
/**
 * 排程達成率 —— 查詢條件欄位。
 *
 * 這一組條件同時決定三件事：統整卡的數字、明細表的內容、CSV 匯出的內容。
 * 按一次查詢三邊一起更新，不會出現「上面的卡片是今天、下面的表是昨天」。
 */

use App\Core\View;
?>

<?php View::component('field', [
    'type'  => 'date',
    'name'  => 'plan_date',
    'label' => '日期',
    'value' => $filters['plan_date'],
    // 不給選未來：明天的實績還不存在，查了只會看到 0 然後來報修
    'attrs' => ['max' => date('Y-m-d')],
    'width' => 150,
]); ?>

<?php View::component('field', [
    'type'    => 'select',
    'name'    => 'schedule_code',
    'label'   => '排程',
    'empty'   => '全部排程',
    'options' => $schedules,
    'value'   => $filters['schedule_code'],
    'width'   => 130,
]); ?>

<?php View::component('field', [
    'type'    => 'select',
    'name'    => 'category',
    'label'   => '產品別',
    'empty'   => '全部',
    'options' => $categories,
    'value'   => $filters['category'],
    'help'    => '只看單一產品別時，統整卡就只剩那一項',
    'width'   => 120,
]); ?>

<?php
/**
 * 一次指定多條線。
 * 現場的用法是從排程表複製一整欄線別貼進來，
 * 所以逗號、換行、空白都要當成分隔符號（後端 Request::multi() 負責切）。
 */
View::component('field', [
    'type'        => 'multi',
    'name'        => 'line_names',
    'label'       => '指定線別',
    'hint'        => '可貼多筆',
    'placeholder' => "一線, 二線\n或一行一筆貼上",
    'value'       => implode(', ', $filters['line_names']),
    'limit'       => 100,
    'width'       => 'grow',
]);
?>
