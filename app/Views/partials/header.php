<?php
/**
 * 全站 header。
 *
 * 由左至右：
 *   1. 使用者頭像 + 工號 + 姓名
 *   2. 目前頁面標題
 *   3. 主選單 / 子選單（依權限自動產生，來源是 config/menu.php）
 *   4. 程式說明
 *   5. 登出
 *   6. 倒數登出
 *
 * 選單內容與首頁功能小卡共用同一份設定，改一次兩邊同步。
 */

use App\Core\Menu;
use App\Core\Url;

$u       = user();
$current = Menu::current();
?>
<header class="app-header">
    <div class="app-header__inner">

        <a class="app-header__brand" href="<?= e(url('/')) ?>" title="回首頁">
            <i class="bi bi-hdd-network"></i>
        </a>

        <!-- 1. 使用者 -->
        <div class="app-user" title="<?= e(($u['dept'] ?? '') ?: '') ?>">
            <span class="app-user__avatar">
                <?php if (!empty($u['avatar'])): ?>
                    <img src="<?= e($u['avatar']) ?>" alt="">
                <?php else: ?>
                    <i class="bi bi-person-fill"></i>
                <?php endif; ?>
            </span>
            <span class="app-user__text">
                <span class="app-user__no"><?= e($u['emp_no'] ?? '') ?></span>
                <span class="app-user__name"><?= e($u['name'] ?? '') ?></span>
            </span>
        </div>

        <span class="app-header__divider"></span>

        <!-- 2. 目前頁面標題 -->
        <div class="app-pagetitle">
            <?php if (!empty($current['icon'])): ?>
                <i class="bi bi-<?= e($current['icon']) ?>"></i>
            <?php endif; ?>
            <span><?= e($title ?? '') ?></span>
        </div>

        <!-- 3. 主選單 / 子選單 -->
        <nav class="app-nav">
            <?php foreach (Menu::tree() as $group): ?>
                <?php
                $groupActive = false;
                foreach ($group['children'] ?? [] as $child) {
                    if (Url::isCurrent($child['url'] ?? null)) {
                        $groupActive = true;
                        break;
                    }
                }
                ?>
                <div class="dropdown app-nav__item">
                    <button class="app-nav__btn <?= $groupActive ? 'is-active' : '' ?>"
                            data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                        <i class="bi bi-<?= e($group['icon'] ?? 'grid') ?>"></i>
                        <span><?= e($group['title']) ?></span>
                        <i class="bi bi-chevron-down app-nav__caret"></i>
                    </button>
                    <ul class="dropdown-menu app-dropdown">
                        <?php foreach ($group['children'] as $child): ?>
                            <li>
                                <a class="dropdown-item <?= Url::isCurrent($child['url'] ?? null) ? 'active' : '' ?>"
                                   href="<?= e(url($child['url'] ?? '#')) ?>">
                                    <i class="bi bi-<?= e($child['icon'] ?? 'dot') ?>"></i>
                                    <span><?= e($child['title']) ?></span>
                                    <?php if (!empty($child['legacy'])): ?>
                                        <!-- 尚未改版的舊頁面，標一下讓使用者知道介面會不一樣 -->
                                        <span class="badge-legacy" title="尚未改版的舊頁面">舊</span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </nav>

        <div class="app-header__right">

            <!-- 4. 程式說明 -->
            <button type="button" class="app-iconbtn" id="btnPageNote"
                    data-note="<?= e($note ?? '') ?>"
                    data-title="<?= e($title ?? '') ?>"
                    title="程式說明">
                <i class="bi bi-info-circle"></i>
                <span class="app-iconbtn__label">程式說明</span>
            </button>

            <!-- 5. 登出 -->
            <a class="app-iconbtn" href="<?= e(url('/logout.php')) ?>" title="登出">
                <i class="bi bi-box-arrow-right"></i>
                <span class="app-iconbtn__label">登出</span>
            </a>

            <!-- 6. 倒數登出（實際逾時判斷在後端，這裡只負責顯示） -->
            <div class="app-countdown" id="sessionCountdown" title="閒置逾時自動登出倒數">
                <i class="bi bi-clock-history"></i>
                <span class="app-countdown__time" data-role="countdown">--:--</span>
            </div>

        </div>
    </div>
</header>
