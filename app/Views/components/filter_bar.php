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
 */

$id = $id ?? 'filterBar';
?>
<form class="app-filter" id="<?= e($id) ?>"
      data-filter-target="<?= e($target ?? '') ?>"
      onsubmit="return false;">

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
</form>
