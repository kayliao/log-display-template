<?php
/**
 * 提示條 —— 一頁最上面那一條「先講清楚再往下看」的說明。
 *
 * 跟公告（announcement）的差別：
 *   公告   內容會換、由維護人員在後台改，一頁可以有很多則、會輪播
 *   提示條 內容是這一頁的規則本身，寫死在頁面裡，永遠只有這一則
 *
 * 「這一頁改的是舊系統的權限，新系統要另外開」這種話就屬於後者 ——
 * 它不會過期，也不該被人在後台關掉。
 *
 *   View::component('notice', [
 *       'level'   => 'warning',
 *       'title'   => '新舊系統的權限不通用',
 *       'content' => '這一頁維護的是舊系統（CLA）的權限，新系統要另外開。',
 *   ]);
 *
 * 參數：
 *   level    info（預設）| warning | danger | success，決定顏色與預設圖示
 *   title    粗體的第一句；不給就只有內文
 *   content  內文（純文字，會自動逸出）
 *   html     內文要放連結或清單時改用這個（自己負責逸出，給了就不看 content）
 *   icon     蓋掉 level 的預設圖示（Bootstrap Icons 名稱，不含 bi- 前綴）
 *   class    額外的 class
 *
 * 放的位置：頁面內容的最上面，`.app-container` 裡面的第一個元素。
 */

$level = $level ?? 'info';

$icons = [
    'info'    => 'info-circle-fill',
    'warning' => 'exclamation-triangle-fill',
    'danger'  => 'exclamation-octagon-fill',
    'success' => 'check-circle-fill',
];

$icon = $icon ?? ($icons[$level] ?? $icons['info']);

$body = isset($html) ? $html : e($content ?? '');

if ($body === '' && empty($title)) {
    return;
}

$class = 'app-notice app-notice--' . $level;

if (!empty($class_extra)) {
    $class .= ' ' . $class_extra;
}
?>
<div class="<?= e($class) ?>" <?php if (!empty($id)): ?>id="<?= e($id) ?>"<?php endif; ?>>
    <i class="bi bi-<?= e($icon) ?> app-notice__icon"></i>
    <div class="app-notice__body">
        <?php if (!empty($title)): ?>
            <div class="app-notice__title"><?= e($title) ?></div>
        <?php endif; ?>
        <?php if ($body !== ''): ?>
            <div class="app-notice__text"><?= $body ?></div>
        <?php endif; ?>
    </div>
</div>
