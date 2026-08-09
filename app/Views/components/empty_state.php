<?php
/**
 * 空狀態。
 *
 *   View::component('empty_state', [
 *       'icon'    => 'inbox',
 *       'title'   => '尚未選擇機台',
 *       'message' => '請從左邊的平面圖點一台機器',
 *   ]);
 *
 * 「沒有資料」跟「還沒查詢」是兩件不同的事，現場常常搞混然後來報修。
 * 用這個元件把話講清楚，順便可以放一顆下一步的按鈕。
 */

use App\Core\View;

$icon    = $icon    ?? 'inbox';
$title   = $title   ?? '沒有資料';
$message = $message ?? '';
$action  = $action  ?? null;
?>
<div class="app-empty<?= !empty($compact) ? ' app-empty--compact' : '' ?>">
    <i class="bi bi-<?= e($icon) ?> app-empty__icon"></i>
    <div class="app-empty__title"><?= e($title) ?></div>

    <?php if ($message !== ''): ?>
        <div class="app-empty__message"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($action): ?>
        <div class="app-empty__action"><?php View::component('button', $action); ?></div>
    <?php endif; ?>
</div>
