<?php
/**
 * 班別產量報表畫面。
 *
 * 跟其他報表頁一樣是「查詢條件列 + 表格」，
 * 差別只在欄位定義多掛了一層 children，表頭就自動變成三層。
 */

use App\Core\View;
?>
<div class="app-container app-container--wide">

    <?php
    View::component('filter_bar', [
        'id'     => 'shiftFilter',
        'target' => 'shiftTable',
        'fields' => View::capture('pages/report/_shift_filters', ['areas' => $areas]),
    ]);
    ?>

    <?php
    View::component('table', [
        'id'      => 'shiftTable',
        'columns' => $columns,
        'api'     => url('/api/report/shift.php'),
        'sort'    => 'machine_id',
        'dir'     => 'asc',
        'size'    => 50,
        'export'  => url('/api/report/shift.php?export=csv'),
        'toolbar' => '<button type="button" class="btn btn-outline-secondary btn-sm" data-role="refresh">'
                   . '<i class="bi bi-arrow-clockwise"></i> 重新整理</button>',
    ]);
    ?>

</div>
