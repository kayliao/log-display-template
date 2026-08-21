<?php
/**
 * 查詢條件列。
 *
 * 報表頁面上方那一排篩選欄位，統一長相與行為：
 *   - 按 Enter 等於按查詢
 *   - 查詢時整列鎖住，避免重複送出
 *   - 「清除」會還原成預設值
 *
 *   View::component('filter_bar', [
 *       'id'      => 'logFilter',
 *       'target'  => 'logTable',   // 查詢後要重新載入哪個表格
 *       'fields'  => View::capture('pages/log/_filters'),  // 自訂欄位 HTML
 *   ]);
 *
 * 條件欄位多的頁面（八個以上、會擠成兩三排）可以讓它收起來：
 *
 *   View::component('filter_bar', [
 *       'id'          => 'wipFilter',
 *       'target'      => 'wipTable',
 *       'collapsible' => true,        // 標題列變成可以按的開關
 *       'collapsed'   => false,       // 一進頁面是展開的
 *       'fields'      => ...,
 *   ]);
 *
 * 參數：
 *   id           元素 id，預設 filterBar
 *   target       查詢後要重新載入哪些表格，逗號分隔多個
 *   fields       自訂欄位 HTML
 *   collapsible  true = 上面多一列可以按的標題，按了整排條件收合
 *   collapsed    true = 一進頁面就是收起來的（沒有 collapsible 時無效）
 *   title        收合標題的文字，預設「條件查詢」
 *
 * 收起來的時候「查詢」「清除」也一起藏起來——它們跟欄位是一組的，
 * 留一顆孤零零的查詢鈕在那裡，使用者不會知道自己按下去是用什麼條件查的。
 */

$id          = $id ?? 'filterBar';
$collapsible = !empty($collapsible);
$collapsed   = $collapsible && !empty($collapsed);

$class = 'app-filter';

if ($collapsible) {
    $class .= ' app-filter--collapsible';
}

if ($collapsed) {
    $class .= ' is-collapsed';
}
?>
<form class="<?= e($class) ?>" id="<?= e($id) ?>"
      data-filter-target="<?= e($target ?? '') ?>"
      onsubmit="return false;">

    <?php if ($collapsible): ?>
        <?php
        /**
         * 用 <button> 而不是可點的 <span>：鍵盤 Tab 過得去、按 Enter 或空白鍵有反應，
         * 螢幕報讀軟體也才知道這是個開關。
         * type="button" 一定要寫，不然在 <form> 裡預設是 submit。
         */
        ?>
        <button type="button" class="app-filter__toggle" data-role="filter-toggle"
                aria-expanded="<?= $collapsed ? 'false' : 'true' ?>"
                aria-controls="<?= e($id) ?>-body">
            <i class="bi bi-search" aria-hidden="true"></i>
            <span><?= e($title ?? '條件查詢') ?></span>
            <i class="bi bi-chevron-up app-filter__caret" aria-hidden="true"></i>
        </button>
    <?php endif; ?>

    <?php if ($collapsible): ?><div class="app-filter__body" id="<?= e($id) ?>-body"><?php endif; ?>

    <div class="app-filter__fields">
        <?= $fields ?? '' ?>
    </div>

    <div class="app-filter__actions">
        <button type="submit" class="btn btn-primary" data-role="filter-submit">
            <i class="bi bi-search"></i> 查詢
        </button>
        <button type="button" class="btn btn-outline-secondary" data-role="filter-reset">
            <i class="bi bi-arrow-counterclockwise"></i> 清除
        </button>
    </div>

    <?php if ($collapsible): ?></div><?php endif; ?>
</form>
