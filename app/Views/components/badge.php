<?php
/**
 * 狀態徽章。
 *
 *   View::component('badge', ['label' => '運轉中', 'status' => 'run']);
 *   View::component('badge', ['label' => '待處理', 'tone' => 'warning']);
 *
 * status 用機台狀態代碼（run / idle / down / alarm / off），顏色跟平面圖、
 * 表格的狀態欄同一份變數，不會出現同一個狀態在兩個地方是不同顏色。
 *
 * tone 用一般語意色（success / warning / danger / info / muted），
 * 給機台狀態以外的東西用。
 */

$label  = $label  ?? '';
$status = strtolower($status ?? '');
$tone   = $tone   ?? '';
$icon   = $icon   ?? '';

$classes = ['app-badge'];

if ($status !== '') {
    $classes[] = 'app-badge--' . preg_replace('/[^a-z0-9\-]/', '', $status);
} elseif ($tone !== '') {
    $classes[] = 'app-badge--' . preg_replace('/[^a-z0-9\-]/', '', $tone);
} else {
    $classes[] = 'app-badge--muted';
}

if (!empty($soft)) {
    $classes[] = 'app-badge--soft';
}
?>
<span class="<?= e(implode(' ', $classes)) ?>"><?php
    if ($icon !== '') {
        echo '<i class="bi bi-' . e($icon) . '"></i> ';
    }
    echo e($label);
?></span>
