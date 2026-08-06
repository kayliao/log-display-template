<?php
/**
 * 分頁籤元件。
 *
 * 一個 tab 裝一張報表是最常見的用法。
 *
 *   View::component('tabs', [
 *       'id'   => 'reportTabs',
 *       'tabs' => [
 *           ['key' => 'summary', 'title' => '彙總', 'icon' => 'table',
 *            'content' => View::capture('pages/report/_summary')],
 *
 *           // lazy = true：切到這個頁籤才去載入，避免一進頁面就打三支 API
 *           ['key' => 'detail', 'title' => '明細', 'icon' => 'list-ul', 'lazy' => true,
 *            'content' => View::capture('pages/report/_detail')],
 *       ],
 *   ]);
 *
 * lazy 的 tab，裡面的表格設定 auto=false，
 * App.tabs 會在該頁籤第一次被打開時才觸發查詢。
 */

$id   = $id ?? 'tabs';
$tabs = $tabs ?? [];
?>
<div class="app-tabs" id="<?= e($id) ?>">
    <ul class="nav nav-tabs app-tabs__nav" role="tablist">
        <?php foreach ($tabs as $i => $tab): ?>
            <?php $key = $tab['key'] ?? ('tab' . $i); ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $i === 0 ? 'active' : '' ?>"
                        id="<?= e($id . '-' . $key) ?>-btn"
                        data-bs-toggle="tab"
                        data-bs-target="#<?= e($id . '-' . $key) ?>"
                        data-lazy="<?= !empty($tab['lazy']) ? '1' : '0' ?>"
                        type="button" role="tab">
                    <?php if (!empty($tab['icon'])): ?>
                        <i class="bi bi-<?= e($tab['icon']) ?>"></i>
                    <?php endif; ?>
                    <span><?= e($tab['title'] ?? '') ?></span>
                    <?php if (isset($tab['badge'])): ?>
                        <span class="app-tabs__badge" data-role="tab-badge"><?= e($tab['badge']) ?></span>
                    <?php endif; ?>
                </button>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="tab-content app-tabs__content">
        <?php foreach ($tabs as $i => $tab): ?>
            <?php $key = $tab['key'] ?? ('tab' . $i); ?>
            <div class="tab-pane fade <?= $i === 0 ? 'show active' : '' ?>"
                 id="<?= e($id . '-' . $key) ?>"
                 role="tabpanel">
                <?= $tab['content'] ?? '' ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
