<?php
/**
 * 功能小卡（首頁用）。
 *
 * 一張卡 = 一個第一層選單。卡片標題是大類（例如「報表」），
 * 底下列出使用者有權限的子功能連結。
 *
 * 用法：
 *   View::component('card', ['group' => $group]);
 *
 * $group 直接吃 config/menu.php 的第一層結構，
 * 所以新增功能只要改選單設定，首頁自動出現。
 *
 * 通常不會單獨叫它，而是透過 menu_grid 一次畫一整面。
 */

use App\Core\Url;
?>
<div class="app-card">
    <div class="app-card__head">
        <span class="app-card__icon">
            <i class="bi bi-<?= e($group['icon'] ?? 'grid') ?>"></i>
        </span>
        <h2 class="app-card__title"><?= e($group['title'] ?? '') ?></h2>
    </div>

    <ul class="app-card__list">
        <?php foreach ($group['children'] ?? [] as $item): ?>
            <li>
                <?php // 主選單彈窗裡會標出「你現在就在這一頁」 ?>
                <a class="app-card__link <?= Url::isCurrent($item['url'] ?? null) ? 'is-current' : '' ?>"
                   href="<?= e(url($item['url'] ?? '#')) ?>">
                    <i class="bi bi-<?= e($item['icon'] ?? 'dot') ?> app-card__link-icon"></i>
                    <span class="app-card__link-text"><?= e($item['title'] ?? '') ?></span>
                    <?php if (!empty($item['legacy'])): ?>
                        <span class="badge-legacy" title="尚未改版的舊頁面">舊</span>
                    <?php endif; ?>
                    <i class="bi bi-chevron-right app-card__arrow"></i>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
