<?php
/**
 * 時間軸甘特圖。
 *
 *   View::component('gantt', [
 *       'id'      => 'machineGantt',
 *       'api'     => url('/api/machine/gantt.php'),
 *       'legend'  => [                                  // 代碼 => 顏色與說明
 *           '0' => ['label' => '稼動', 'color' => 'var(--eq-status-0)'],
 *           '1' => ['label' => '停機', 'color' => 'var(--eq-status-1)'],
 *       ],
 *       'params'  => ['eq_type' => 'E23'],              // 每次查詢都帶的固定參數
 *       'filter'  => '#f_eq_type',                      // 要連動的下拉（CSS 選擇器）
 *       'auto'    => false,                             // 放在分頁籤裡時用
 *       'barLabel'=> true,                              // 把持續時間寫在區段裡（預設不寫）
 *       'drill'   => ['api' => url('/api/machine/detail.php'), 'param' => 'machine_id'],
 *   ]);
 *
 * 這是通用元件，不綁機台。任何「一列一個對象、橫軸是時間、上面有帶顏色的區段」
 * 都可以用：機台稼動、排程進度、水化槽佔用、人員班表。
 *
 * API 要回傳的形狀（欄位名固定，跟資料表叫什麼無關）：
 *
 *   {
 *     "start": "2026-09-01 00:00:00",      // 時間軸左界
 *     "end":   "2026-09-08 00:00:00",      // 時間軸右界
 *     "rows": [
 *       { "key":   "E29-LP1543400",        // 唯一值，點擊時傳給 drill
 *         "label": "B01-01",               // 左側列標題
 *         "group": "移印機",                // 副標（可省略）
 *         "bars": [
 *           { "code":  "0",                // 對應 legend 的鍵
 *             "start": "2026-09-01 08:00:00",
 *             "end":   "2026-09-01 12:30:00",
 *             "tip":   "稼動 4h30m"        // 滑鼠停留顯示；不給就自動組
 *           }
 *         ]
 *       }
 *     ],
 *     "summary": { "0": 123456, "1": 7890 } // 各代碼的總秒數（可省略）
 *   }
 *
 * 圖形是一般的 HTML 元素，不是 SVG，也沒有引入繪圖套件。
 * 用 HTML 是為了兩件 SVG 做不到的事：**橫向捲動時左邊的機台名稱要留在原地、
 * 垂直捲動時上方的時間刻度要留在原地**（position: sticky）。
 * 詳細理由見 public/assets/js/app.gantt.js 的檔頭。
 *
 * ⚠ 區段一律以「秒」為單位換算成寬度，不要用筆數或平均值——
 *   甘特圖的重點就是長短，用錯基準整張圖的意義就沒了。
 */

$id = $id ?? 'gantt';

/**
 * 顏色與說明。鍵要跟 API 回傳的 bars[].code 對得起來。
 * 預設給的是機台狀態那一套（跟平面圖、狀態總表同一份顏色），
 * 其他用途自己傳 legend 蓋掉。
 */
$legend = $legend ?? [
    '0' => ['label' => '稼動',       'color' => 'var(--eq-status-0)'],
    '1' => ['label' => '停機',       'color' => 'var(--eq-status-1)'],
    '2' => ['label' => '警報',       'color' => 'var(--eq-status-2)'],
    '3' => ['label' => '待料',       'color' => 'var(--eq-status-3)'],
    '4' => ['label' => '不停機警報', 'color' => 'var(--eq-status-4)'],
    '5' => ['label' => '連線異常',   'color' => 'var(--eq-status-5)'],
    '7' => ['label' => 'RD 測試',    'color' => 'var(--eq-status-7)'],
];

$config = [
    'id'     => $id,
    'api'    => $api ?? null,
    'legend' => $legend,

    // 一列的高度與列間距（像素）
    'rowHeight' => (int) ($rowHeight ?? 22),
    'rowGap'    => (int) ($rowGap ?? 6),

    // 左側列標題欄的寬度
    'labelWidth' => (int) ($labelWidth ?? 132),

    /**
     * 要不要把持續時間寫在區段裡面。預設不寫。
     *
     * 甘特圖是靠「長短」與「顏色」讀的，長度本身就是那個數字的圖形版；
     * 再把 4h30m 印上去等於同一件事講兩次，而且只有夠寬的區段印得下——
     * 結果是一排長條有些有字有些沒有，看起來像資料缺了一塊。
     *
     * 需要精確數字的時候滑鼠移上去就有，而且 tooltip 永遠是完整的
     * （窄到只剩一像素的區段也讀得到）。
     *
     * 真的要印在圖上（例如列印給現場貼牆）才打開它。
     */
    'barLabel' => (bool) ($barLabel ?? false),

    /**
     * 每次查詢都會帶上的固定參數，例如 ['floor' => '2F']。
     * 一頁放多張圖時靠這個讓每張圖查自己那一份。
     */
    'params' => (object) ($params ?? []),

    /**
     * 要連動哪一個下拉（CSS 選擇器）。不給就不連動——
     * 一頁上有多張圖時，不該有一張圖偷偷去抓別人的篩選器。
     */
    'filter' => $filter ?? null,

    /**
     * 點擊區段要開的詳細資料彈窗。
     *   ['api' => url('/api/machine/detail.php'), 'param' => 'machine_id']
     * param 是「要用 rows[].key 填哪一個參數名」。不給就不能點。
     */
    'drill' => $drill ?? null,

    /** false = 不要一載入就查（放在分頁籤裡時用） */
    'auto' => $auto ?? true,

    /** 查無資料時顯示的字 */
    'empty' => $empty ?? '這段期間沒有資料。',

    /**
     * 直接把資料交給它，不打 API。
     *
     * 形狀跟 API 回傳的一模一樣（見檔頭）。給了 data 就不會再去打 api，
     * 兩個用途：
     *   - 頁面在後端已經把資料查好了，不需要再繞一次 HTTP
     *   - 元件目錄那種「只是要看長相」的靜態示範
     *
     * 條件列的重新查詢仍然需要 api——沒有 api 的圖按查詢不會有反應，
     * 這是可預期的（它本來就是一張靜態圖）。
     */
    'data' => $data ?? null,
];
?>
<div class="app-gantt" id="<?= e($id) ?>-wrap"
     data-gantt-id="<?= e($id) ?>"
     data-gantt-config='<?= e(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>

    <div class="app-gantt__bar">
        <div class="app-gantt__legend" data-role="gantt-legend">
            <?php foreach ($legend as $code => $meta): ?>
                <span class="app-gantt__legend-item">
                    <i class="app-gantt__swatch" style="background: <?= e($meta['color']) ?>"></i>
                    <?= e($meta['label']) ?>
                </span>
            <?php endforeach; ?>
        </div>

        <div class="app-gantt__tools">
            <!--
              落後提示。排程沒跑或落後太久時會出現在這裡，
              不要讓使用者看著一張少了半天的圖以為是機台沒動。
            -->
            <span class="app-gantt__lag" data-role="gantt-lag" hidden></span>

            <button type="button" class="btn btn-outline-secondary btn-sm"
                    data-role="gantt-zoom-out" title="時間軸縮小">
                <i class="bi bi-zoom-out"></i>
            </button>
            <span class="app-gantt__zoom" data-role="gantt-zoom-level">100%</span>
            <button type="button" class="btn btn-outline-secondary btn-sm"
                    data-role="gantt-zoom-in" title="時間軸放大">
                <i class="bi bi-zoom-in"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm"
                    data-role="gantt-reset" title="還原檢視">
                <i class="bi bi-arrows-fullscreen"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm"
                    data-role="gantt-refresh" title="重新整理">
                <i class="bi bi-arrow-clockwise"></i>
            </button>
        </div>
    </div>

    <!--
      這裡是雙向的捲動容器。上方刻度與左側機台名稱靠 position: sticky
      釘在裡面，所以捲到哪裡都知道「這是哪一台、現在幾點」。
      限高是刻意的：八十台機台會撐出兩千多像素，整頁捲的話刻度早就不見了。
    -->
    <div class="app-gantt__stage">
        <div class="app-gantt__canvas" data-role="gantt-canvas">
            <!-- 內容由 App.gantt 產生 -->
        </div>
    </div>

    <div class="app-gantt__summary" data-role="gantt-summary"></div>
</div>
