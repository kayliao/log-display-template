<?php
/**
 * 全站 header。
 *
 * 由左至右：
 *   1. 使用者頭像 + 工號 + 姓名
 *   2. 目前頁面標題
 *   3. 主選單（跳出彈窗，列出所有能用的功能小卡）
 *      子選單（下拉，列出目前這個大項目底下的子功能）
 *   4. 程式說明
 *   5. 登出
 *   6. 倒數登出
 *
 * 主選單與子選單的內容都來自 config/menu.php，而且已經被 Menu 依權限濾過，
 * 沒有權限的功能連出現都不會出現。首頁的功能小卡吃的是同一份，改一次兩邊同步。
 *
 * 兩顆按鈕本身是共用元件（modal_button / dropdown），
 * 想在其他地方放一顆「按一下展開」的按鈕，直接叫同樣的元件就好。
 *
 * ⚠ 主選單彈窗的「本體」不在這裡，在 partials/overlays.php。
 *   header 是 sticky + z-index，彈窗放進來會被自己的遮罩蓋住。
 */

use App\Core\Menu;
use App\Core\Url;
use App\Core\View;

$u       = user();
$current = Menu::current();
$group   = Menu::currentGroup();
$cards   = Menu::cards();

// 子選單的選項：目前大項目底下的子功能
$subItems = [];

foreach ($group['children'] ?? [] as $child) {
    $subItems[] = [
        'title'       => $child['title'] ?? '',
        'icon'        => $child['icon'] ?? 'dot',
        'url'         => $child['url'] ?? '#',
        'active'      => Url::isCurrent($child['url'] ?? null),
        // 尚未改版的舊頁面，標一下讓使用者知道介面會不一樣
        'badge'       => !empty($child['legacy']) ? '舊' : null,
        'badge_title' => '尚未改版的舊頁面',
    ];
}
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

            <?php if ($cards !== []): ?>
                <?php View::component('modal_button', [
                    'target' => 'appMenuModal',
                    'icon'   => 'grid-3x3-gap-fill',
                    'label'  => '主選單',
                    'title'  => '所有可使用的功能',
                ]); ?>
            <?php endif; ?>

            <?php if ($subItems !== []): ?>
                <?php View::component('dropdown', [
                    'icon'   => $group['icon'] ?? 'list-ul',
                    'label'  => $group['title'] ?? '子選單',
                    'title'  => $group['title'] . ' 底下的功能',
                    'active' => true,
                    'items'  => $subItems,
                    'width'  => 240,
                ]); ?>
            <?php endif; ?>

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
