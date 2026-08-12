<?php
/**
 * 共用元件目錄。
 *
 * 每一段都是「實際渲染出來的樣子」加上「產生它的那段 PHP」，
 * 要用哪個元件直接把程式碼複製走。
 *
 * 這一頁刻意不抽成元件——它本來就是拿來展示元件的，
 * 再包一層反而看不出原本的寫法。
 */

use App\Core\View;

/**
 * 一個展示區塊：標題 + 說明 + 實際元件 + 對應的程式碼。
 */
$demo = function (string $title, string $note, callable $render, string $code) {
    echo '<section class="dev-demo">';
    echo '<h3 class="dev-demo__title">' . e($title) . '</h3>';

    if ($note !== '') {
        echo '<p class="dev-demo__note">' . e($note) . '</p>';
    }

    echo '<div class="dev-demo__stage">';
    $render();
    echo '</div>';

    echo '<pre class="dev-demo__code"><code>' . e(trim($code)) . '</code></pre>';
    echo '</section>';
};
?>
<div class="app-container app-container--wide">

    <div class="app-panel">
        <div class="app-panel__body">
            <p class="dev-intro">
                這一頁列出所有共用元件的實際長相與寫法。元件檔在
                <code>app/Views/components/</code>，一個檔案一個元件，
                裡面的註解就是完整的參數說明。
            </p>
        </div>
    </div>

    <?php
    // ======================================================================
    $demo(
        '表單欄位 field',
        '全站所有輸入框的最小單位。查詢條件列與編輯表單用的是同一顆元件，'
        . '所以 label 字級、必填星號、圖示內距這些細節不會每頁長得不一樣。',
        function () {
            echo '<div class="dev-row">';
            View::component('field', ['name' => 'demo_id', 'label' => '機台編號', 'value' => 'M-101', 'required' => true]);
            View::component('field', ['type' => 'select', 'name' => 'demo_area', 'label' => '廠區',
                'empty' => '全部', 'options' => ['A' => 'A 區', 'B' => 'B 區', 'C' => 'C 區'], 'value' => 'B']);
            View::component('field', ['name' => 'demo_kw', 'label' => '關鍵字', 'icon' => 'search',
                'placeholder' => '機台編號 / 名稱', 'width' => 'grow']);
            View::component('field', ['type' => 'number', 'name' => 'demo_min', 'label' => '停機門檻',
                'value' => 30, 'suffix' => '分鐘', 'help' => '超過這個時間才列入統計']);
            echo '</div>';

            echo '<div class="dev-row">';
            View::component('field', ['type' => 'radio', 'name' => 'demo_shift', 'label' => '班別',
                'options' => ['D' => '白班', 'N' => '夜班'], 'value' => 'D', 'inline' => true]);
            View::component('field', ['type' => 'switch', 'name' => 'demo_auto', 'label' => '自動更新',
                'text' => '每 30 秒重新整理', 'value' => 1]);
            View::component('field', ['type' => 'static', 'label' => '最後回報', 'value' => '2026-08-09 18:32:34']);
            View::component('field', ['name' => 'demo_err', 'label' => '工號', 'value' => 'E9',
                'error' => '工號必須是 5 碼']);
            echo '</div>';

            echo '<div class="dev-row">';
            View::component('field', ['type' => 'textarea', 'name' => 'demo_remark', 'label' => '備註',
                'width' => 'block', 'rows' => 2, 'placeholder' => '這裡可以寫多行']);
            echo '</div>';
        },
        <<<'CODE'
View::component('field', ['name' => 'machine_id', 'label' => '機台編號', 'required' => true]);

View::component('field', ['type' => 'select', 'name' => 'area', 'label' => '廠區',
    'empty' => '全部', 'options' => ['A' => 'A 區', 'B' => 'B 區']]);

View::component('field', ['name' => 'keyword', 'label' => '關鍵字',
    'icon' => 'search', 'width' => 'grow']);

View::component('field', ['type' => 'number', 'name' => 'mins', 'label' => '停機門檻',
    'suffix' => '分鐘', 'help' => '超過這個時間才列入統計']);

// type 還有：textarea / radio / checklist / checkbox / switch / static
// 有 error 就自動變紅框並顯示訊息
CODE
    );

    // ======================================================================
    $demo(
        '一次輸入多筆的搜尋框 field（type => multi）',
        '現場的用法是從 Excel 或工單複製一整欄編號貼進來，所以逗號、頓號、分號、'
        . '空白、換行、Tab 都當成分隔符號（半形全形都算）。前端不切字串，'
        . '原樣送給後端由 Request::multi() 處理，兩邊只有一套規則。'
        . '實際用在「班別產量報表」的查詢條件列。',
        function () {
            echo '<div class="dev-row">';
            View::component('field', [
                'type'        => 'multi',
                'name'        => 'demo_ids',
                'label'       => '指定機台',
                'hint'        => '可貼多筆',
                'value'       => "M-101, M-102\nM-103\nM-104 M-105",
                'help'        => '逗號、空白或換行都可以分隔；留空表示不限',
                'limit'       => 200,
                'width'       => 'grow',
            ]);
            echo '</div>';
        },
        <<<'CODE'
// 畫面
View::component('field', [
    'type'  => 'multi',
    'name'  => 'machine_ids',
    'label' => '指定機台',
    'hint'  => '可貼多筆',
    'limit' => 200,               // 超過會在框下方標紅字提醒
]);

// API：切成陣列，超過上限直接擋（訊息會顯示給使用者）
$filters['machine_ids'] = Request::multi('machine_ids', 200);

// Repository：陣列不能直接塞進具名參數，用 Sql::in() 產生 IN (...)
if (!empty($filters['machine_ids'])) {
    [$clause, $inBind] = Sql::in('m.machine_id', $filters['machine_ids']);
    $sql  .= ' AND ' . $clause;
    $bind  = array_merge($bind, $inBind);
}
CODE
    );

    // ======================================================================
    $demo(
        '表單 form',
        '給一份欄位定義就長出整張表單，跟 table 元件是同一個想法：'
        . '版面與間距由元件決定，頁面只描述「有哪些欄位」。span => full 的欄位佔滿整列。',
        function () {
            View::component('form', [
                'id'      => 'demoForm',
                'columns' => 2,
                'sections' => [
                    ['title' => '基本資料', 'icon' => 'info-circle', 'fields' => [
                        ['name' => 'machine_id',   'label' => '機台編號', 'value' => 'M-101', 'required' => true],
                        ['name' => 'machine_name', 'label' => '機台名稱', 'value' => 'A 線 1 號機'],
                        ['name' => 'model',        'label' => '機型',     'value' => 'CNC-500'],
                        ['type' => 'select', 'name' => 'area', 'label' => '廠區',
                         'options' => ['A', 'B', 'C'], 'value' => 'A'],
                        ['type' => 'textarea', 'name' => 'remark', 'label' => '備註',
                         'span' => 'full', 'rows' => 2],
                    ]],
                ],
                'actions' => [
                    ['label' => '取消'],
                    ['label' => '儲存', 'variant' => 'primary', 'icon' => 'check-lg', 'type' => 'button'],
                ],
            ]);
        },
        <<<'CODE'
View::component('form', [
    'columns'  => 2,
    'sections' => [
        ['title' => '基本資料', 'icon' => 'info-circle', 'fields' => [
            ['name' => 'machine_id', 'label' => '機台編號', 'required' => true],
            ['name' => 'machine_name', 'label' => '機台名稱'],
            ['type' => 'textarea', 'name' => 'remark', 'label' => '備註', 'span' => 'full'],
        ]],
    ],
    'actions' => [
        ['label' => '取消'],
        ['label' => '儲存', 'variant' => 'primary', 'icon' => 'check-lg', 'type' => 'submit'],
    ],
]);
CODE
    );

    // ======================================================================
    $demo(
        '按鈕 button / button_group',
        '有給 url 就是連結、沒給就是按鈕，但兩者長得一樣——'
        . '使用者不需要知道背後是 <a> 還是 <button>。',
        function () {
            View::component('button_group', ['buttons' => [
                ['label' => '查詢', 'icon' => 'search', 'variant' => 'primary'],
                ['label' => '清除', 'icon' => 'arrow-counterclockwise'],
                ['label' => '匯出 CSV', 'icon' => 'file-earmark-arrow-down', 'url' => '#'],
                ['label' => '刪除', 'icon' => 'trash', 'variant' => 'danger'],
                ['label' => '重新整理', 'icon' => 'arrow-clockwise', 'iconOnly' => true],
            ]]);
        },
        <<<'CODE'
View::component('button_group', [
    'align'   => 'right',          // left | center | right | between
    'buttons' => [
        ['label' => '查詢', 'icon' => 'search', 'variant' => 'primary'],
        ['label' => '匯出 CSV', 'url' => url('/api/x.php?export=csv')],
        ['label' => '重新整理', 'icon' => 'arrow-clockwise', 'iconOnly' => true],
    ],
]);
CODE
    );

    // ======================================================================
    $demo(
        '徽章 badge',
        '機台狀態用 status（顏色跟平面圖同一份變數），其他情況用 tone。'
        . '整片表格裡放太多實心徽章會很吵，那時候加 soft。',
        function () {
            echo '<div class="dev-inline">';
            foreach (['run' => '運轉中', 'idle' => '待機', 'down' => '停機', 'alarm' => '異常', 'off' => '關機'] as $status => $label) {
                View::component('badge', ['label' => $label, 'status' => $status]);
            }
            echo '</div><div class="dev-inline">';
            foreach (['success' => '已完成', 'warning' => '待確認', 'danger' => '逾期', 'info' => '處理中', 'muted' => '未指派'] as $tone => $label) {
                View::component('badge', ['label' => $label, 'tone' => $tone]);
            }
            echo '</div><div class="dev-inline">';
            foreach (['success' => '已完成', 'warning' => '待確認', 'danger' => '逾期', 'info' => '處理中'] as $tone => $label) {
                View::component('badge', ['label' => $label, 'tone' => $tone, 'soft' => true]);
            }
            echo '</div>';
        },
        <<<'CODE'
View::component('badge', ['label' => '運轉中', 'status' => 'run']);
View::component('badge', ['label' => '待確認', 'tone' => 'warning']);
View::component('badge', ['label' => '逾期',   'tone' => 'danger', 'soft' => true]);
CODE
    );

    // ======================================================================
    $demo(
        '資訊小卡 stat_card',
        '一行一個數字，只放最重要的幾項，給「一眼掃過去」用。'
        . 'bar 會在該列下方畫進度條，delta 顯示跟上期的變化。',
        function () {
            echo '<div class="dev-cards">';

            View::component('stat_card', [
                'title'    => 'M-101 今日概況',
                'subtitle' => '08:00 起',
                'icon'     => 'speedometer2',
                'items'    => [
                    ['label' => '稼動率', 'value' => 25, 'unit' => '%', 'tone' => 'danger',
                     'bar' => 25, 'delta' => -8.4, 'hint' => '低於目標 75%'],
                    ['label' => '良品', 'value' => 1280, 'format' => 'number', 'delta' => 3.2],
                    ['label' => '不良', 'value' => 32, 'format' => 'number', 'tone' => 'warning'],
                    ['label' => '目前狀態', 'badge' => ['label' => '運轉中', 'status' => 'run']],
                ],
            ]);

            View::component('stat_card', [
                'title' => 'A 線今日達成',
                'icon'  => 'bullseye',
                'items' => [
                    ['label' => '達成率', 'value' => 92.4, 'unit' => '%', 'tone' => 'success', 'bar' => 92.4],
                    ['label' => '產出',   'value' => 18420, 'format' => 'number', 'unit' => '件'],
                    ['label' => '停機',   'value' => 46, 'unit' => '分鐘', 'tone' => 'muted'],
                ],
                'footer' => '資料更新於 5 分鐘前',
            ]);

            echo '</div>';
        },
        <<<'CODE'
View::component('stat_card', [
    'title' => 'M-101 今日概況',
    'icon'  => 'speedometer2',
    'items' => [
        ['label' => '稼動率', 'value' => 25, 'unit' => '%',
         'tone' => 'danger', 'bar' => 25, 'delta' => -8.4, 'hint' => '低於目標 75%'],
        ['label' => '良品', 'value' => 1280, 'format' => 'number'],
        ['label' => '目前狀態', 'badge' => ['label' => '運轉中', 'status' => 'run']],
    ],
]);
CODE
    );

    // ======================================================================
    $demo(
        '達成率統整卡 achievement',
        '「今天這個排程，預計做多少、實際做多少、達成率多少」——'
        . '分類明細與合計放在同一張卡上。合計不用自己算，元件會把 items 加起來，'
        . '順便算出每一項佔實際的百分比；自己算一份傳進來的話，'
        . '遲早會出現「上面幾項加起來不等於下面的合計」。'
        . '顏色是門檻決定的：達到 target 綠、達到 warn 黃、再低紅。'
        . '實際用在「排程達成率」那一頁（會跟著查詢條件列重查）。',
        function () {
            View::component('achievement', [
                'title'    => '水化排程達成',
                'subtitle' => date('Y-m-d') . '（今日）',
                'icon'     => 'droplet-half',
                'unit'     => '片',
                'items'    => [
                    ['label' => '白片', 'plan' => 12400, 'actual' => 11350, 'color' => '#0891b2'],
                    ['label' => '彩片', 'plan' => 5200,  'actual' => 5310,  'color' => '#7c3aed'],
                ],
                'footer'   => '資料更新至 ' . date('H:i') . '　／　超出的部分不會讓進度條爆版',
            ]);

            echo '<div class="dev-gap"></div>';

            // 預計是 0 的那一項：不算達成率也不算佔比，用灰色標示「今天沒排」
            View::component('achievement', [
                'title'      => '研磨排程達成（含未排程的項目）',
                'icon'       => 'gear-wide-connected',
                'unit'       => '片',
                'totalLabel' => '全日達成率',
                'summary'    => 'bottom',
                'items'      => [
                    ['label' => '白片', 'plan' => 8000, 'actual' => 6180],
                    ['label' => '彩片', 'plan' => 3000, 'actual' => 2960],
                    ['label' => '樣品', 'plan' => 0,    'actual' => 0, 'hint' => '今日未排程'],
                ],
            ]);
        },
        <<<'CODE'
View::component('achievement', [
    'id'       => 'hydrationAchv',
    'title'    => '水化排程達成',
    'subtitle' => '2026-08-12（今日）',
    'icon'     => 'droplet-half',
    'unit'     => '片',
    'items'    => [
        ['label' => '白片', 'plan' => 12400, 'actual' => 11350, 'color' => '#0891b2'],
        ['label' => '彩片', 'plan' => 5200,  'actual' => 5310,  'color' => '#7c3aed'],
    ],
]);

// 合計、達成率、佔比都是元件算的，只要給 plan 與 actual 兩個數字。
// target => 100（預設）達到就綠色、warn => 90 以上黃色、再低紅色
// summary => 'bottom' 把合計那一塊移到明細下面
// share   => 'plan'   佔比改成看預計而不是看實際

// --- 要跟著查詢條件重查就給 api ---
View::component('achievement', [
    'id'    => 'scheduleAchv',
    'api'   => url('/api/report/schedule_summary.php'),
    'items' => $summary['items'],   // 後端先算好的初始值，一進頁面就有數字
    'auto'  => false,               // 初始值已經在畫面上了，不用再自動打一次 API
]);

// 查詢條件列把卡片跟表格一起指定，按一次查詢兩邊同時更新
View::component('filter_bar', ['target' => 'scheduleAchv,scheduleTable', ...]);

// API 回傳 { items: [{ label, plan, actual, color? }], title?, subtitle?, footer? }
CODE
    );

    // ======================================================================
    $demo(
        '單筆資料直立顯示 record',
        '表格是「一筆一列」，那是拿來比較很多筆用的。只看一筆的時候改用這個：'
        . '兩欄等寬、由左至右填，欄位名在左、值在右。跟表格一樣支援大項掛小項。'
        . '放大鏡彈窗裡的「基本資料」用的就是這一套長相，前端產生的 HTML 跟這裡一模一樣。',
        function () {
            View::component('record', [
                'title'   => 'M-101　A 線 1 號機',
                'icon'    => 'hdd-network',
                'columns' => 2,
                'fields'  => [
                    ['label' => '機台編號', 'value' => 'M-101', 'mono' => true],
                    ['label' => '機台名稱', 'value' => 'A 線 1 號機'],
                    ['label' => '機型',     'value' => 'CNC-500'],
                    ['label' => '廠區',     'value' => 'A'],
                    ['label' => '製造商',   'value' => '台中精機'],
                    ['label' => '安裝日期', 'value' => '2023-04-18', 'format' => 'date'],
                    ['label' => '目前狀態', 'badge' => ['label' => '運轉中', 'status' => 'run']],
                    ['label' => '最後回報', 'value' => '2026-08-09 18:32:34', 'mono' => true],

                    ['title' => '今日累計', 'children' => [
                        ['title' => '時間分佈（分鐘）', 'children' => [
                            ['label' => '運轉', 'value' => 642, 'format' => 'number'],
                            ['label' => '待機', 'value' => 118, 'format' => 'number'],
                            ['label' => '停機', 'value' => 46,  'format' => 'number'],
                            ['label' => '稼動率', 'value' => 79.7, 'format' => 'percent'],
                        ]],
                        ['title' => '產量', 'children' => [
                            ['label' => '良品', 'value' => 1280, 'format' => 'number'],
                            ['label' => '不良', 'value' => 32,   'format' => 'number'],
                        ]],
                    ]],

                    ['label' => '備註', 'span' => 'full',
                     'value' => '主軸於 2026-07 更換軸承，下次保養預定 2026-10。'],
                ],
            ]);
        },
        <<<'CODE'
View::component('record', [
    'title'   => 'M-101　A 線 1 號機',
    'columns' => 2,                        // 兩欄等寬，由左至右填
    'fields'  => [
        ['label' => '機台編號', 'value' => 'M-101', 'mono' => true],
        ['label' => '目前狀態', 'badge' => ['label' => '運轉中', 'status' => 'run']],

        // 大項底下掛小項，要幾層就掛幾層
        ['title' => '今日累計', 'children' => [
            ['title' => '產量', 'children' => [
                ['label' => '良品', 'value' => 1280, 'format' => 'number'],
                ['label' => '不良', 'value' => 32,   'format' => 'number'],
            ]],
        ]],

        ['label' => '備註', 'value' => '……', 'span' => 'full'],
    ],
]);

// 要在彈窗裡顯示同樣的東西：後端 Service 回傳
//   ['type' => 'fields', 'title' => '基本資料', 'columns' => 2, 'fields' => [...]]
// 前端 app.modal.js 會畫出一模一樣的結構。
CODE
    );

    // ======================================================================
    $demo(
        '指北針 compass',
        '廠區的格線是照地面標線畫的，通常不會剛好對正北。'
        . '角度是設定值（config/app.php 的 map.north_offset），改角度只要改一個數字，'
        . '不用重畫圖再換檔，放大也不會糊掉。',
        function () {
            echo '<div class="dev-inline dev-inline--wide">';
            foreach ([0, 23.5, -15, 90] as $angle) {
                View::component('compass', ['angle' => $angle, 'label' => '偏 ' . $angle . '°', 'size' => 92]);
            }
            echo '</div>';
        },
        <<<'CODE'
// 平面圖會自己帶上，一般不用手動放
View::component('compass', ['angle' => 23.5, 'label' => '廠區座標北']);

// config/app.php
'map' => [
    'north_offset'     => 23.5,        // 北方偏右 23.5 度；偏左填負數
    'compass_position' => 'top-right', // none = 不顯示
],
CODE
    );

    // ======================================================================
    $demo(
        '檔案上傳匯入 upload',
        '兩段式：上傳後只做解析與驗證並列出問題，使用者確認沒問題才真的寫入。'
        . '現場的檔案十次有三次是錯的，一步寫入的話錯誤發生時資料已經進去一半，要人工回頭清。'
        . '這個元件比較大，直接看實際頁面比較準。',
        function () {
            View::component('button_group', ['buttons' => [
                ['label' => '打開機台清單匯入', 'icon' => 'cloud-arrow-up', 'variant' => 'primary',
                 'url' => url('/pages/machine/import.php')],
                ['label' => '下載範本看格式', 'icon' => 'download',
                 'url' => url('/api/machine/import.php?action=template')],
            ]]);
        },
        <<<'CODE'
View::component('upload', [
    'id'       => 'machineImport',
    'api'      => url('/api/machine/import.php'),
    'accept'   => '.csv,.txt',
    'maxSize'  => 5,                                          // MB
    'template' => url('/api/machine/import.php?action=template'),
    'columns'  => MachineImportService::columns(),            // 欄位說明表直接從驗證定義產生
]);

// 後端 API 要支援三個 action：
//   template  下載範本（表頭就是驗證用的那一份，不會「照範本填卻說欄位不對」）
//   preview   收檔 -> 解析 -> 逐列驗證 -> 回傳前 20 筆與所有錯誤（不寫入）
//   commit    帶 preview 拿到的 token -> 重新驗證 -> 整批寫入（同一個交易）

// 檔案解析交給 Csv::read()，它處理掉現場最常見的三個坑：
//   Big5 編碼（Excel 另存的預設）、UTF-8 BOM、逗號還是 Tab 分隔
CODE
    );

    // ======================================================================
    $demo(
        '空狀態 empty_state',
        '「沒有資料」跟「還沒查詢」是兩件不同的事，現場常常搞混然後來報修。'
        . '用這個把話講清楚，順便放一顆下一步的按鈕。',
        function () {
            View::component('empty_state', [
                'icon'    => 'hand-index',
                'title'   => '尚未選擇機台',
                'message' => '請從左邊的平面圖點一台機器，這裡會顯示它的詳細資料',
                'compact' => true,
                'action'  => ['label' => '回到平面圖', 'icon' => 'grid-3x3', 'variant' => 'primary'],
            ]);
        },
        <<<'CODE'
View::component('empty_state', [
    'icon'    => 'hand-index',
    'title'   => '尚未選擇機台',
    'message' => '請從左邊的平面圖點一台機器',
    'action'  => ['label' => '回到平面圖', 'variant' => 'primary'],
]);
CODE
    );
    ?>

    <div class="app-panel">
        <div class="app-panel__head">
            <h3 class="app-panel__title"><i class="bi bi-tools"></i> <span>改成自己的組合</span></h3>
        </div>
        <div class="app-panel__body">
            <p class="dev-intro">
                這些元件就是 <code>app/Views/components/</code> 底下的純 PHP 檔，
                沒有編譯、沒有註冊表、沒有繼承關係，可以直接改、也可以複製一份改成自己的。
                三種做法：
            </p>

            <ol class="app-steps">
                <li>
                    <strong>直接組合現成的</strong>
                    <span><code>form</code> 吃的是一份陣列，欄位清單可以用程式產生
                          （依角色決定哪些唯讀、從欄位定義自動長出來）。</span>
                </li>
                <li>
                    <strong>把常用組合包成自己的元件</strong>
                    <span>同一組欄位在三頁都出現時，存成
                          <code>app/Views/components/machine_form.php</code>，
                          之後一行 <code>View::component('machine_form', [...])</code> 就好。</span>
                </li>
                <li>
                    <strong>從零寫一個</strong>
                    <span>複製一個現有元件當骨架。規則只有三條：參數先給預設值、
                          輸出包 <code>e()</code>、樣式用 <code>:root</code> 的變數不要寫死色碼。</span>
                </li>
            </ol>

            <p class="app-upload__spec-note">
                完整寫法見 <code>docs/HOW-TO.md</code> 的「我要組自己的表單 / 做自己的元件」。
                注意元件<strong>不會</strong>繼承外層頁面的變數，要用什麼就明確傳進去——
                這是刻意的，否則頁面的 <code>$title</code> 會意外變成表單的標題。
            </p>
        </div>
    </div>

    <div class="app-panel">
        <div class="app-panel__head">
            <h3 class="app-panel__title"><i class="bi bi-box-seam"></i> <span>其他既有元件</span></h3>
        </div>
        <div class="app-panel__body">
            <table class="app-subtable">
                <thead>
                    <tr><th style="width:150px">元件</th><th>說明</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>table</code></td><td>報表表格。一份欄位定義決定表頭、排序、放大鏡與 CSV 匯出。表頭層數不限，見「班別產量報表」。</td></tr>
                    <tr><td><code>filter_bar</code></td><td>查詢條件列，按查詢自動重載指定的表格。</td></tr>
                    <tr><td><code>date_range</code></td><td>日期區間，超出上限的日期在日曆上直接不能點。</td></tr>
                    <tr><td><code>split</code></td><td>版面分欄，<code>1-2</code>、<code>1-1-1</code> 這樣寫，窄螢幕自動改上下排。</td></tr>
                    <tr><td><code>panel</code></td><td>白底方框（可有標題列），分欄後每一欄裝東西用。</td></tr>
                    <tr><td><code>tabs</code></td><td>分頁籤，<code>lazy</code> 的頁籤第一次打開才查資料。</td></tr>
                    <tr><td><code>modal</code> / <code>modal_button</code></td><td>彈窗與開啟它的按鈕。</td></tr>
                    <tr><td><code>dropdown</code></td><td>下拉選單，header 的主選單與子選單就是這個。</td></tr>
                    <tr><td><code>machine_map</code></td><td>廠內機台平面圖（原生 SVG，含指北針）。</td></tr>
                    <tr><td><code>card</code> / <code>menu_grid</code></td><td>功能小卡與小卡牆，首頁與主選單彈窗共用。</td></tr>
                    <tr><td><code>announcement</code></td><td>公告提醒列，多則自動輪播。</td></tr>
                </tbody>
            </table>
        </div>
    </div>

</div>
