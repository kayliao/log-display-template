<?php
/**
 * 每日生產日報 —— 畫面。
 *
 * 標準結構就是「查詢條件列 + 表格」。
 * 需要多張表就用 tabs 元件包起來（寫法見 app/Views/pages/log/machine.php）。
 */

use App\Core\View;
?>
<div class="app-container">

    <?php
    // 查詢條件列。target 指定按下查詢後要重新載入哪張表格（多張用逗號分隔）
    View::component('filter_bar', [
        'id'     => 'dailyFilter',
        'target' => 'dailyTable',
        'fields' => View::capture('pages/report/_daily_filters'),
    ]);
    ?>

    <?php
    View::component('table', [
        'id'      => 'dailyTable',
        'columns' => $columns,
        'api'     => url('/api/report/daily.php'),
        'sort'    => 'col1',        // 預設排序欄位
        'dir'     => 'asc',
        'size'    => 50,
        'auto'    => true,          // false = 要按查詢才載入（資料量大的報表建議設 false）
        'export'  => url('/api/report/daily.php?export=csv'),
    ]);
    ?>

</div>
