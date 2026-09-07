<?php
/**
 * 查詢條件列。
 *
 * 報表頁面上方那一排篩選欄位，統一長相與行為：
 *   - 按 Enter 等於按查詢
 *   - 查詢時整列鎖住，避免重複送出
 *   - 「清除」會還原成 old() 第二個參數給的預設值，不是網址上那組條件
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
 *   scope        同一頁有兩排以上條件列時給，當作網址上的分組名稱
 *   keep         按查詢時要在網址上保留的參數，逗號分隔，預設 'p,v'
 *
 * 一頁兩排條件列的時候要給 scope。條件會被記在網址上（重新整理或
 * 把連結貼給同事看到的是同一份畫面），兩排都有「關鍵字」時，不分組就會
 * 共用到同一個 keyword，重新整理後同一個字同時出現在兩排裡：
 *
 *   View::component('filter_bar', [
 *       'id'     => 'userFilter',
 *       'scope'  => 'user',           // 網址寫成 ?user[keyword]=A123
 *       'target' => 'userTable',
 *       'fields' => View::capture('pages/xxx/_user_filters', ['scope' => 'user']),
 *   ]);
 *
 * scope 只影響網址，送給後端 API 的參數名不變（還是 keyword）。
 * 條件欄位那邊要用同一個名字取值：old('keyword', '', $scope)。
 *
 * 按查詢時網址上只會留下「路由參數 + 各排條件列的欄位」，別人帶來的雜訊
 * 查一次就被洗掉。路由參數預設是 p 與 v（index.php?p=<頁面>&v=<分頁> 這種），
 * 這一頁還有別的參數要留就給 keep：
 *
 *   View::component('filter_bar', ['keep' => 'p,v,mode', ...]);
 *
 * 收起來的時候「查詢」「清除」也一起藏起來——它們跟欄位是一組的，
 * 留一顆孤零零的查詢鈕在那裡，使用者不會知道自己按下去是用什麼條件查的。
 */

$id          = $id ?? 'filterBar';
$scope       = $scope ?? '';
$collapsible = !empty($collapsible);
$collapsed   = $collapsible && !empty($collapsed);

/**
 * 「清除」要還原成什麼值。
 *
 * 欄位那份檔每呼叫一次 old()，它就把自己的預設值（old() 的第二個
 * 參數）登記到 $GLOBALS 裡；這裡把自己那一組拿走，印成屬性交給前端。
 * 不這樣做的話前端只能把「頁面載入時畫面上的值」當預設值，而那個值
 * 是從網址上填進來的——帶著條件重新整理後按清除就像沒反應。
 *
 * 拿完要清掉：一頁兩排條件列都沒給 scope 的話（舊頁面可能這樣），
 * 下面那排才不會撿到上面那排登記的預設值。
 *
 * 樣板裡寫死的欄位（沒走 old()）不會在這份名單裡，前端遇到這種欄位
 * 會自動退回「還原成頁面載入時的值」，行為跟以前一樣。
 */
$filterDefaults = $GLOBALS['__app_filter_defaults'][$scope] ?? [];
unset($GLOBALS['__app_filter_defaults'][$scope]);

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
      data-filter-scope="<?= e($scope) ?>"
      data-filter-keep="<?= e($keep ?? 'p,v') ?>"
      data-filter-defaults="<?= e(json_encode($filterDefaults, JSON_UNESCAPED_UNICODE)) ?>"
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
