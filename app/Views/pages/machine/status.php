<?php
/**
 * 機台狀態總表畫面。
 *
 * 版面就是「查詢條件列 + 表格」，這是本系統所有報表頁的標準結構。
 */

use App\Core\View;
?>
<div class="app-container">

    <?php
    // 查詢條件：先把欄位 HTML 準備好，再交給 filter_bar 元件排版
    $fields = View::capture('pages/machine/_status_filters', ['areas' => $areas]);

    View::component('filter_bar', [
        'id'     => 'statusFilter',
        'target' => 'statusTable',   // 按查詢時要重新載入的表格 id
        'fields' => $fields,
    ]);
    ?>

    <?php
    View::component('table', [
        'id'      => 'statusTable',
        'columns' => $columns,
        'api'     => url('/api/machine/list.php'),
        'sort'    => 'machine_id',
        'dir'     => 'asc',
        'size'    => 50,
        'export'  => url('/api/machine/list.php?export=csv'),
        'toolbar' => '<button type="button" class="btn btn-outline-secondary btn-sm" data-role="refresh">'
                   . '<i class="bi bi-arrow-clockwise"></i> 重新整理</button>',
    ]);
    ?>

</div>
