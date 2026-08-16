<?php
/**
 * 水化排程畫面。
 *
 * 版面（沒有用分頁籤，三塊都看得到）：
 *
 *   ┌───────────────────────┬─────────────────────────────┐
 *   │  上傳水化排程          │  今日統整                    │
 *   │  （拖檔、驗證、匯入）   │  （數字小卡 + 分佈 + 取號進度）│
 *   ├───────────────────────┴─────────────────────────────┤
 *   │  查詢條件                                            │
 *   │  明細表（可排序、可匯出、可點放大鏡）                  │
 *   └─────────────────────────────────────────────────────┘
 *
 * 上半用 split 的 1-1，兩塊等寬；1100px 以下會自動變上下排，
 * 現場的舊螢幕不會被擠爆。
 */

use App\Core\View;

$upload = View::componentHtml('upload', [
    'id'       => 'aquaImport',
    'api'      => url('/api/hydration/import.php'),
    'accept'   => '.csv,.txt,.xlsx',
    'maxSize'  => 5,
    'template' => url('/api/hydration/import.php?action=template'),
    'columns'  => $importColumns,

    // 這一頁的重點：有問題的列只會被跳過，不會擋住整批
    'partial'  => true,

    /**
     * 匯完順手重新載入，使用者不用自己按重新整理：
     *   aquaTable   下面的明細表
     *   aquaToday   右上角的數字小卡（今日筆數、數量、未取號）
     *   aquaCycles  右上角的各次水化分佈
     *   aquaAchv    右上角的取號進度（達成率統整卡）
     *
     * 剛匯進去的排程日期就是今天，統整不跟著動的話會像是檔案沒進去。
     * 逗號分隔的這一串會同時丟給表格、數字小卡、達成率卡三支模組，
     * 各自挑自己認得的 id，不是自己的就跳過。
     * 三張卡指到同一支 API，重抓時會合併成一次呼叫。
     */
    'reload'   => 'aquaTable,aquaToday,aquaCycles,aquaAchv',
]);

$noImport = View::componentHtml('empty_state', [
    'icon'    => 'shield-lock',
    'title'   => '沒有匯入權限',
    'message' => '你可以查詢與匯出資料，但不能上傳水化排程。需要的話請找系統管理員開權限。',
    'compact' => true,
]);
?>
<div class="app-container app-container--wide">

    <?php
    // ── 上半：左邊上傳、右邊今日統整 ────────────────────────────
    View::component('split', [
        'ratio' => '1-1',

        'left' => View::componentHtml('panel', [
            'title'   => '上傳水化排程',
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
        'id'     => 'aquaFilter',
        'target' => 'aquaTable',
        'fields' => View::capture('pages/hydration/_schedule_filters'),
    ]);
    ?>

    <?php
    View::component('table', [
        'id'      => 'aquaTable',
        'columns' => $columns,
        'api'     => url('/api/hydration/list.php'),
        'sort'    => 'aqua_schedule_date',
        'dir'     => 'desc',
        'size'    => 50,
        'export'  => url('/api/hydration/list.php?export=csv'),
        'toolbar' => '<button type="button" class="btn btn-outline-secondary btn-sm" data-role="refresh">'
                   . '<i class="bi bi-arrow-clockwise"></i> 重新整理</button>',
    ]);
    ?>

</div>
