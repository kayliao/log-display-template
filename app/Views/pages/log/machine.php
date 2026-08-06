<?php
/**
 * 機台 Log 查詢畫面。
 *
 * 用分頁籤裝兩張表：明細與統計。
 * 統計那張設成 lazy，切過去才查，避免一進頁面就打兩支 API。
 */

use App\Core\View;

$filters = View::capture('pages/log/_filters', ['areas' => $areas]);

$detailTab = View::componentHtml('table', [
    'id'      => 'logTable',
    'columns' => $columns,
    'api'     => url('/api/log/list.php'),
    'sort'    => 'log_time',
    'dir'     => 'desc',
    'size'    => 100,
    'auto'    => false,   // 要按下查詢才載入，避免沒選條件就撈全部
    'export'  => url('/api/log/list.php?export=csv'),
    'empty'   => '請設定查詢條件後按「查詢」',
]);

$summaryTab = View::componentHtml('table', [
    'id'      => 'logSummaryTable',
    'columns' => [
        ['key' => 'event_type', 'title' => '事件類型'],
        ['key' => 'cnt',        'title' => '筆數', 'align' => 'right', 'format' => 'number'],
    ],
    'api'     => url('/api/log/summary.php'),
    'paging'  => false,
    'auto'    => false,
]);
?>
<div class="app-container">

    <?php
    View::component('filter_bar', [
        'id'     => 'logFilter',
        'target' => 'logTable,logSummaryTable',   // 一次重新載入兩張表
        'fields' => $filters,
    ]);
    ?>

    <?php
    View::component('tabs', [
        'id'   => 'logTabs',
        'tabs' => [
            ['key' => 'detail',  'title' => 'Log 明細', 'icon' => 'list-ul', 'content' => $detailTab],
            ['key' => 'summary', 'title' => '類型統計', 'icon' => 'pie-chart', 'lazy' => true, 'content' => $summaryTab],
        ],
    ]);
    ?>

</div>
