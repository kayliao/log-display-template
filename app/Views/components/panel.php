<?php
/**
 * 面板 —— 白底、有框、可以有標題的一個區塊。
 *
 * split 分欄之後每一欄要裝東西，裝在這個框裡才跟表格、平面圖是同一套長相。
 *
 *   View::component('panel', [
 *       'title'   => '查詢與統計',
 *       'icon'    => 'sliders',
 *       'content' => $html,
 *   ]);
 *
 * 參數：
 *   title    標題文字；不給就不畫標題列
 *   icon     標題前的圖示
 *   tools    標題列右側的按鈕 HTML
 *   content  內容 HTML
 *   flush    true = 內容不留內距（要塞滿版的表格時用）
 *   class    額外 class
 */

$class = 'app-panel';

if (!empty($flush)) {
    $class .= ' app-panel--flush';
}

if (!empty($class_extra)) {
    $class .= ' ' . $class_extra;
}
?>
<div class="<?= e($class) ?>" <?php if (!empty($id)): ?>id="<?= e($id) ?>"<?php endif; ?>>

    <?php if (!empty($title) || !empty($tools)): ?>
        <div class="app-panel__head">
            <h3 class="app-panel__title">
                <?php if (!empty($icon)): ?>
                    <i class="bi bi-<?= e($icon) ?>"></i>
                <?php endif; ?>
                <span><?= e($title ?? '') ?></span>
            </h3>
            <?php if (!empty($tools)): ?>
                <div class="app-panel__tools"><?= $tools ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="app-panel__body">
        <?= $content ?? '' ?>
    </div>
</div>
