<?php
/**
 * 觸發按鈕（內部元件，不要在頁面直接用）。
 *
 * 「按一下會展開東西」的按鈕全站只有這一份長相 ——
 * 下拉選單（dropdown）與開彈窗的按鈕（modal_button）都是叫這一支畫出來的，
 * 所以兩種按鈕不會一個高一點、一個圓一點。
 *
 * 底線開頭 = 內部元件的命名慣例，看到就知道不是給頁面用的。
 *
 *   View::componentHtml('_trigger', [
 *       'variant' => 'nav',                              // nav | icon
 *       'icon'    => 'grid-3x3-gap',
 *       'label'   => '主選單',
 *       'caret'   => true,                               // 右邊要不要小箭頭
 *       'active'  => false,                              // 標成「目前所在」
 *       'attrs'   => ['data-bs-toggle' => 'dropdown'],   // 額外屬性
 *   ]);
 *
 * variant：
 *   nav   選單列上那種（.app-nav__btn），窄螢幕會只剩圖示
 *   icon  header 右側那種（.app-iconbtn）
 */

$variant = (($variant ?? 'nav') === 'icon') ? 'icon' : 'nav';
$attrs   = $attrs ?? [];
$label   = (string) ($label ?? '');

$class = $variant === 'icon' ? 'app-iconbtn' : 'app-nav__btn';

if (!empty($active)) {
    $class .= ' is-active';
}

if (!empty($class_extra)) {
    $class .= ' ' . $class_extra;
}

$labelClass = $variant === 'icon' ? 'app-iconbtn__label' : 'app-nav__label';
?>
<button type="button" class="<?= e($class) ?>"
    <?php if (!empty($id)): ?> id="<?= e($id) ?>"<?php endif; ?>
    <?php if (!empty($title)): ?> title="<?= e($title) ?>"<?php endif; ?>
    <?php if (!empty($disabled)): ?> disabled<?php endif; ?>
    <?php foreach ($attrs as $name => $value): ?> <?= e($name) ?>="<?= e($value) ?>"<?php endforeach; ?>>
    <?php if (!empty($icon)): ?>
        <i class="bi bi-<?= e($icon) ?>"></i>
    <?php endif; ?>
    <?php if ($label !== ''): ?>
        <span class="<?= e($labelClass) ?>"><?= e($label) ?></span>
    <?php endif; ?>
    <?php if (!empty($caret)): ?>
        <i class="bi bi-chevron-down app-nav__caret"></i>
    <?php endif; ?>
</button>
