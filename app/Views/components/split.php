<?php
/**
 * 版面分欄。
 *
 * 「左邊 1/3 放資料、右邊 2/3 放平面圖」這種版型，
 * 每頁自己刻 CSS grid 遲早會刻出五種不一樣的間距，所以統一在這裡。
 *
 *   View::component('split', [
 *       'ratio' => '1-2',                       // 左 1 份、右 2 份
 *       'left'  => View::componentHtml('panel', [...]),
 *       'right' => View::componentHtml('machine_map', [...]),
 *   ]);
 *
 * 三欄以上就給 panes（比例的份數要跟欄數一樣多）：
 *
 *   View::component('split', [
 *       'ratio' => '1-2-1',
 *       'panes' => [$a, $b, $c],
 *   ]);
 *
 * 參數：
 *   ratio    欄寬比例，用 - 分隔，例如 '1-2'、'2-1'、'1-3'、'1-1-1'。預設 '1-1'
 *   left     左欄內容（等同 panes[0]）
 *   right    右欄內容（等同 panes[1]）
 *   panes    欄位內容陣列，給了就不看 left / right
 *   sticky   哪一欄要跟著捲動（0 = 第一欄）。左邊是篩選、右邊是長表格時很有用
 *   gap      欄距（px），預設 16
 *   align    欄位垂直對齊：stretch（預設，等高）| start（各自長多高算多高）
 *
 * 窄螢幕（1100px 以下）會自動改成上下排列，現場的舊螢幕不會被擠爆。
 * 不想讓它換行就傳 'stack' => false。
 *
 * ⚠ 間距是用 CSS 變數 --split-gap 傳給樣式表的，不是直接寫 gap。
 *   這樣「上下排的時候要比左右排更鬆」那條規則才有辦法蓋過來 ——
 *   直接寫 inline 的 gap 會贏過樣式表，除非到處加 !important。
 */

$panes = isset($panes) ? array_values($panes) : [];

if ($panes === []) {
    foreach ([$left ?? null, $right ?? null] as $pane) {
        if ($pane !== null) {
            $panes[] = $pane;
        }
    }
}

if ($panes === []) {
    return;
}

// 比例字串 -> fr 單位。看不懂的字元一律當成 1，不要因為打錯字整個版面壞掉。
$weights = [];

foreach (preg_split('/[^0-9]+/', (string) ($ratio ?? '1-1')) as $piece) {
    if ($piece !== '') {
        $weights[] = max(1, (int) $piece);
    }
}

// 比例份數跟欄數對不起來時，缺的補 1、多的砍掉
while (count($weights) < count($panes)) {
    $weights[] = 1;
}

$weights = array_slice($weights, 0, count($panes));

$columns = [];

foreach ($weights as $weight) {
    $columns[] = $weight . 'fr';
}

$class = 'app-split';

if (($stack ?? true) === false) {
    $class .= ' app-split--nostack';
}

if (($align ?? '') === 'start') {
    $class .= ' app-split--start';
}

$sticky = isset($sticky) ? (int) $sticky : -1;
?>
<div class="<?= e($class) ?>"
     style="grid-template-columns: <?= e(implode(' ', $columns)) ?>; --split-gap: <?= (int) ($gap ?? 16) ?>px">
    <?php foreach ($panes as $i => $pane): ?>
        <div class="app-split__pane <?= $i === $sticky ? 'app-split__pane--sticky' : '' ?>">
            <?= $pane ?>
        </div>
    <?php endforeach; ?>
</div>
