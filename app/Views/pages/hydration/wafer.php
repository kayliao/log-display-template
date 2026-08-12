<?php
/**
 * 水化管理畫面。
 *
 * 版面（沒有用分頁籤，三塊都看得到）：
 *
 *   ┌───────────────────────┬───────────────────────┐
 *   │  上傳水化紀錄          │  今日統整              │
 *   │  （拖檔、驗證、匯入）   │  （數字小卡 + 各次分佈）│
 *   ├───────────────────────┴───────────────────────┤
 *   │  查詢條件                                      │
 *   │  明細表（可排序、可匯出、可點放大鏡）            │
 *   └───────────────────────────────────────────────┘
 *
 * 上半用 split 的 1-1，兩塊等高等寬；1100px 以下會自動變上下排，
 * 現場的舊螢幕不會被擠爆。
 */

use App\Core\View;

$upload = View::componentHtml('upload', [
    'id'       => 'hydImport',
    'api'      => url('/api/hydration/import.php'),
    'accept'   => '.csv,.txt',
    'maxSize'  => 5,
    'template' => url('/api/hydration/import.php?action=template'),
    'columns'  => $importColumns,

    // 這一頁的重點：有問題的列只會被跳過，不會擋住整批
    'partial'  => true,

    // 匯完順手把下面那張表重新載入，使用者不用自己按重新整理
    'reload'   => 'hydTable',
]);

$noImport = View::componentHtml('empty_state', [
    'icon'    => 'shield-lock',
    'title'   => '沒有匯入權限',
    'message' => '你可以查詢與匯出資料，但不能上傳水化紀錄。需要的話請找系統管理員開權限。',
    'compact' => true,
]);
?>
<div class="app-container app-container--wide">

    <?php
    // ── 上半：左邊上傳、右邊今日統整 ────────────────────────────
    View::component('split', [
        'ratio' => '1-1',

        'left' => View::componentHtml('panel', [
            'title'   => '上傳水化紀錄',
            'icon'    => 'cloud-arrow-up',
            // 匯入的規則（順序、覆蓋、失敗怎麼辦）就寫在上傳框下面，
            // 現場不用先去翻文件才知道為什麼被擋
            'content' => !empty($canImport)
                ? $upload . View::capture('pages/hydration/_import_note')
                : $noImport,
        ]),

        'right' => View::componentHtml('panel', [
            'title'   => '今日統整',
            'icon'    => 'clipboard-data',
            'content' => View::capture('pages/hydration/_today', ['summary' => $summary]),
        ]),
    ]);
    ?>

    <?php
    // ── 下半：查詢條件 + 明細 ──────────────────────────────────
    View::component('filter_bar', [
        'id'     => 'hydFilter',
        'target' => 'hydTable',
        'fields' => View::capture('pages/hydration/_wafer_filters'),
    ]);
    ?>

    <?php
    View::component('table', [
        'id'      => 'hydTable',
        'columns' => $columns,
        'api'     => url('/api/hydration/list.php'),
        'sort'    => 'hyd_date',
        'dir'     => 'desc',
        'size'    => 50,
        'export'  => url('/api/hydration/list.php?export=csv'),
        'toolbar' => '<button type="button" class="btn btn-outline-secondary btn-sm" data-role="refresh">'
                   . '<i class="bi bi-arrow-clockwise"></i> 重新整理</button>',
    ]);
    ?>

</div>
