<?php
/**
 * 公告提醒列。
 *
 * 首頁預設會顯示，但做成共用元件，任何頁面都可以掛：
 *   View::component('announcement', ['items' => $announcements]);
 *
 * $items 每一筆的欄位：
 *   level    'info' | 'warning' | 'danger'（決定顏色與圖示）
 *   title    標題
 *   content  內容
 *   date     發佈日期
 *
 * 多筆時會自動輪播（純 CSS + 少量 JS，不另外拉套件）。
 */

$items = $items ?? [];

if ($items === []) {
    return;
}

$icons = [
    'info'    => 'megaphone',
    'warning' => 'exclamation-triangle',
    'danger'  => 'exclamation-octagon',
];
?>
<div class="app-announce" id="appAnnounce" data-count="<?= count($items) ?>">
    <div class="app-announce__label">
        <i class="bi bi-megaphone-fill"></i>
        <span>公告</span>
    </div>

    <div class="app-announce__viewport">
        <?php foreach ($items as $i => $item): ?>
            <?php $level = $item['level'] ?? 'info'; ?>
            <div class="app-announce__item app-announce__item--<?= e($level) ?> <?= $i === 0 ? 'is-active' : '' ?>"
                 data-index="<?= $i ?>">
                <i class="bi bi-<?= e($icons[$level] ?? 'megaphone') ?>"></i>
                <span class="app-announce__title"><?= e($item['title'] ?? '') ?></span>
                <span class="app-announce__text"><?= e($item['content'] ?? '') ?></span>
                <?php if (!empty($item['date'])): ?>
                    <span class="app-announce__date"><?= e($item['date']) ?></span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (count($items) > 1): ?>
        <div class="app-announce__nav">
            <button type="button" class="app-announce__btn" data-role="announce-prev" aria-label="上一則">
                <i class="bi bi-chevron-up"></i>
            </button>
            <span class="app-announce__counter">
                <span data-role="announce-index">1</span>/<?= count($items) ?>
            </span>
            <button type="button" class="app-announce__btn" data-role="announce-next" aria-label="下一則">
                <i class="bi bi-chevron-down"></i>
            </button>
        </div>
    <?php endif; ?>
</div>
