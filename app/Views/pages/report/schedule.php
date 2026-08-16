<?php
/**
 * 排程達成率畫面。
 *
 * 版面由上而下就是現場看數字的順序：
 *
 *   查詢條件（今天哪一個排程）
 *        ↓
 *   達成率統整卡（白片多少、彩片多少、合計多少、還差多少）
 *        ↓
 *   明細／匯入（要追是哪一條線落後，或是把最新的實績傳上來）
 *
 * 條件列的 target 同時寫上卡片與表格的 id，按一次查詢兩邊一起更新——
 * 卡片是合計、表格是明細，兩個數字對不起來是最難解釋的狀況。
 */

use App\Core\View;

$detailTab = View::componentHtml('table', [
    'id'      => 'scheduleTable',
    'columns' => $columns,
    'api'     => url('/api/report/schedule.php'),
    'sort'    => 'line_name',
    'dir'     => 'asc',
    'size'    => 50,
    'export'  => url('/api/report/schedule.php?export=csv'),
    'toolbar' => '<button type="button" class="btn btn-outline-secondary btn-sm" data-role="refresh">'
               . '<i class="bi bi-arrow-clockwise"></i> 重新整理</button>',
]);

$importTab = View::componentHtml('split', [
    'ratio'  => '2-1',
    'sticky' => 1,

    'left' => View::componentHtml('panel', [
        'title'   => '上傳排程與實績',
        'icon'    => 'cloud-arrow-up',
        'content' => View::componentHtml('upload', [
            'id'       => 'scheduleImport',
            'api'      => url('/api/report/schedule_import.php'),
            'accept'   => '.csv,.txt,.xlsx',
            'maxSize'  => 5,
            'template' => url('/api/report/schedule_import.php?action=template'),
            'columns'  => $importColumns,

            /**
             * 匯完把統整卡與明細表一起重載。
             *
             * 傳上來的就是這個排程的實績，數字不跟著動的話，
             * 使用者會切回「各線明細」看到舊的達成率，以為檔案沒進去。
             * 兩者都跟著目前條件列的條件重查，所以卡片與表格仍然是同一天的數字。
             */
            'reload'   => 'scheduleAchv,scheduleTable',
        ]),
    ]),

    'right' => View::componentHtml('panel', [
        'title'   => '說明',
        'icon'    => 'info-circle',
        'content' => View::capture('pages/report/_schedule_import_note'),
    ]),
]);

$tabs = [
    ['key' => 'detail', 'title' => '各線明細', 'icon' => 'list-ul', 'content' => $detailTab],
];

// 沒有匯入權限的人連頁籤都不用看到，按了才說沒權限是最差的做法
if (!empty($canImport)) {
    $tabs[] = ['key' => 'import', 'title' => '匯入排程／實績', 'icon' => 'cloud-arrow-up', 'content' => $importTab];
}
?>
<div class="app-container app-container--wide">

    <?php
    View::component('filter_bar', [
        'id'     => 'scheduleFilter',
        'target' => 'scheduleAchv,scheduleTable',   // 統整卡與明細表一起重載
        'fields' => View::capture('pages/report/_schedule_filters', [
            'filters'    => $filters,
            'schedules'  => $schedules,
            'categories' => $categories,
        ]),
    ]);
    ?>

    <?php
    /**
     * 達成率統整卡。
     *
     * items 是後端先算好的初始值（一進頁面就有數字可看），
     * 給了 api 之後按「查詢」就由前端重新跟後端要。
     * auto = false：初始資料已經在畫面上了，不需要再自動打一次 API。
     */
    View::component('achievement', [
        'id'       => 'scheduleAchv',
        'title'    => $summary['title'],
        'subtitle' => $summary['subtitle'],
        'icon'     => 'bullseye',
        'unit'     => '片',
        'items'    => $summary['items'],
        'footer'   => $summary['footer'],

        'target'   => 100,   // 達成率 100% 以上是綠色
        'warn'     => 90,    // 90% 以上黃色，再低紅色

        'api'      => url('/api/report/schedule_summary.php'),
        'auto'     => false,
    ]);
    ?>

    <?php
    View::component('tabs', [
        'id'   => 'scheduleTabs',
        'tabs' => $tabs,
    ]);
    ?>

</div>
