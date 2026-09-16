<?php
/**
 * 廠內機台平面圖。
 *
 *   View::component('machine_map', [
 *       'id'      => 'shopMap',
 *       'api'     => url('/api/machine/map.php'),
 *       'axisX'   => range('A', 'L'),      // 橫軸標籤
 *       'axisY'   => range(1, 10),         // 縱軸標籤
 *       'cell'    => 88,                   // 一格多寬
 *       'cellY'   => 48,                   // 一格多高（不給就跟寬度一樣）
 *       'legend'  => [...],                // 狀態 => 顏色說明
 *       'north'   => 23.5,                 // 北方偏角，預設讀 config('app.map.north_offset')
 *       'params'  => ['floor' => '2F'],    // 每次查詢都帶上的固定參數
 *       'filter'  => '#f_map_area',        // 要連動的下拉（CSS 選擇器）；不給就不連動
 *       'auto'    => false,                // 不要一載入就查（放在分頁籤裡時用）
 *
 *       // 看板用的選用功能，不給就跟以前一樣
 *       'refresh'   => 10,                 // 每 10 秒自動重查；0 = 不自動更新
 *       'snapshot'  => ['machine_ip' => '電腦 IP', ...],   // 格子可以切換顯示哪些欄位
 *       'detailApi' => url('/api/machine/detail.php'),     // 點格子開哪一支（預設就是這支）
 *       'tankApi'   => url('/api/machine/tank.php'), // 後端標記 tank 的格子改打這支
 *       'exportApi' => url('/api/machine/map_export.php'),  // 有給才出現匯出鈕
 *   ]);
 *
 * 圖形本身用 SVG 由 App.machineMap 畫出來，沒有引入任何繪圖套件——
 * 需求很單純（座標定位的矩形 + 文字），自己畫反而好維護，
 * 也少一個要離線佈署的相依。
 *
 * API 回傳的每台機器：
 *   { "machine_id":"M-101", "model":"CNC-500", "x":"A", "y":3, "w":2, "h":1, "status":"RUN" }
 *   x 是橫軸代號、y 是縱軸號碼、w/h 是佔幾格（機台長寬）。
 */

use App\Core\View;

$id     = $id ?? 'machineMap';
$axisX  = $axisX ?? range('A', 'J');
$axisY  = $axisY ?? range(1, 8);

/**
 * 指北針。角度預設讀 config，所以整廠只要在 config/app.php 設定一次，
 * 每一張平面圖都會轉到同一個方向。個別頁面要蓋掉再傳 north 就好。
 */
$north           = $north           ?? config('app.map.north_offset', 0);
$compassPosition = $compassPosition ?? config('app.map.compass_position', 'bar');
$compassLabel    = $compassLabel    ?? config('app.map.compass_label', '');
$compassFormat   = $compassFormat   ?? config('app.map.compass_angle_format', 'signed');

/**
 * 狀態顏色。跟 app.css 的 --status-* 變數對應，改色只要改一個地方。
 *
 * ★ dark => true 表示**這個底色是淺的，格子裡要配深色字**。
 *
 *   標在這裡而不是寫在 CSS 裡列狀態代碼：改配色的人跟要標深淺的人
 *   是同一個，兩件事放在同一行才不會走散。漏標的症狀是「那幾台的字
 *   看不見」——不報錯，只有畫面上看得出來。
 *
 *   換一組自己的圖例時記得跟著標，淺色底沒標就會變成白字配淺底。
 */
$legend = $legend ?? [
    'RUN'   => ['label' => '運轉中', 'color' => 'var(--status-run)'],
    'IDLE'  => ['label' => '待機',   'color' => 'var(--status-idle)',  'dark' => true],
    'DOWN'  => ['label' => '停機',   'color' => 'var(--status-down)'],
    'ALARM' => ['label' => '異常',   'color' => 'var(--status-alarm)'],
    'OFF'   => ['label' => '關機',   'color' => 'var(--status-off)',   'dark' => true],
];

$config = [
    'id'     => $id,
    'api'    => $api ?? null,
    'axisX'  => array_values($axisX),
    'axisY'  => array_values($axisY),
    'legend' => $legend,
    'cell'   => $cell ?? 88,     // 一格多寬（像素）

    /**
     * 一格多高。不給就跟寬度一樣（正方形格子，另外兩頁平面圖走這條）。
     *
     * ★ 廠房不是正方形的。橫向要放得下「機台編號 + 機種」兩行字，
     *   縱向只是「第幾排」，用不著跟寬度一樣高——列高等於欄寬的話
     *   整張圖會被拉得很長，一個螢幕看不完。
     *   要調整某一頁的疏密，改傳進來的這個數字就好，程式不用動。
     */
    'cellY'  => $cellY ?? null,

    'gap'    => $gap ?? 6,

    /**
     * 每次查詢都會帶上的固定參數，例如 ['floor' => '2F']。
     * 分頁版平面圖就是靠這個讓每個頁籤查自己那一層。
     */
    'params' => (object) ($params ?? []),

    /**
     * 要連動哪一個下拉選單（CSS 選擇器）。
     * 不給就不連動——一頁上有多張圖時，不該有一張圖偷偷去抓別人的篩選器。
     */
    'filter' => $filter ?? null,

    /**
     * false = 不要一載入就查，等別人叫它（分頁籤切過去時才查）。
     */
    'auto'   => $auto ?? true,

    /**
     * 點格子要打哪一支 API。
     *
     * 預設是機台詳細資料，跟表格的放大鏡同一支。
     * tankApi 是給「這一格要看的是別的東西」用的——目前只有水槽（看槽況），
     * 哪一台走哪一支由後端在資料裡標記（machines[].tank），前端不判斷機種。
     */
    'detailApi' => $detailApi ?? url('/api/machine/detail.php'),
    'tankApi'   => $tankApi ?? null,

    /**
     * 每幾秒自動重新查一次。0（預設）= 不自動更新。
     *
     * 看板性質的頁面才需要。一般查詢頁不要開——
     * 使用者正在看的資料在腳底下換掉是很難用的。
     */
    'refresh' => (int) ($refresh ?? 0),
];
?>
<div class="app-map" id="<?= e($id) ?>-wrap"
     data-map-config='<?= e(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>

    <div class="app-map__bar">
        <?php if ($compassPosition === 'bar'): ?>
            <!--
              指北針放在工具列裡，不疊在畫布上。
              疊在角落會壓到那一區的機台，現場剛好在那個位置的機器就看不到了。
            -->
            <?php View::component('compass', [
                'angle'    => $north,
                'label'    => $compassLabel,
                'format'   => $compassFormat,
                'position' => 'bar',
                'size'     => 34,
            ]); ?>
        <?php endif; ?>

        <div class="app-map__legend">
            <?php foreach ($legend as $status => $meta): ?>
                <span class="app-map__legend-item">
                    <i class="app-map__swatch" style="background: <?= e($meta['color']) ?>"></i>
                    <?= e($meta['label']) ?>
                </span>
            <?php endforeach; ?>
        </div>

        <div class="app-map__tools">
            <?php if ($snapshot ?? []): ?>
                <!--
                  資訊快照：把格子裡的字換成別的欄位（電腦 IP、各種版本）。
                  只是換一個欄位顯示，不會重打 API。
                -->
                <label class="app-map__snapshot">
                    <span class="app-map__snapshot-label">格子顯示</span>
                    <select class="form-select form-select-sm" data-role="map-snapshot">
                        <!--
                          value="" = 顯示格子預設的字（label，也就是機台編號）。
                          ⚠ 傳進來的 snapshot 清單不要再放一次那個欄位，
                            不然下拉裡會有兩個一模一樣的選項。
                        -->
                        <option value=""><?= e($snapshotDefault ?? '機台編號') ?></option>
                        <?php foreach ($snapshot as $field => $label): ?>
                            <option value="<?= e($field) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>

            <?php if ($config['refresh'] > 0): ?>
                <!--
                  這一份資料是什麼時候的。看板掛在牆上，沒有這個時間就分不出
                  「現在整廠都沒動」與「這頁早就沒在更新了」。
                -->
                <span class="app-map__stamp">
                    更新於 <span data-role="map-refresh-time">—</span>
                </span>

                <button type="button" class="btn btn-outline-secondary btn-sm" data-role="map-pause"
                        title="暫停自動更新，畫面就不會在你看的時候換掉">
                    <i class="bi bi-pause-fill"></i> 暫停更新
                </button>
            <?php endif; ?>

            <button type="button" class="btn btn-outline-secondary btn-sm" data-role="map-zoom-out" title="縮小">
                <i class="bi bi-zoom-out"></i>
            </button>
            <span class="app-map__zoom" data-role="map-zoom-level">100%</span>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-role="map-zoom-in" title="放大">
                <i class="bi bi-zoom-in"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-role="map-reset" title="還原檢視">
                <i class="bi bi-arrows-fullscreen"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-role="map-refresh" title="重新整理">
                <i class="bi bi-arrow-clockwise"></i>
            </button>

            <?php if ($exportApi ?? null): ?>
                <!--
                  匯出。

                  ★ 是一個單純的 <a>，沒有掛任何 JS。

                    匯出的是**整廠**，不分樓層（2026-09-15 現場確認），
                    所以沒有任何參數要帶——一個連結就夠了。
                    掛 JS 去 preventDefault 再改寫 location 是一樣的結果，
                    只是多一段會壞的程式，而且 JS 沒載到時連匯出都沒了。
                -->
                <a class="btn btn-outline-secondary btn-sm" data-role="map-export"
                   href="<?= e($exportApi) ?>" title="匯出整廠的機台資料">
                    <i class="bi bi-download"></i> 匯出
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!--
      畫布與指北針是兄弟節點，不是父子。
      指北針要「釘」在角落，捲動平面圖時不能跟著跑掉，
      所以它不能放在會捲動的 .app-map__canvas 裡面。
    -->
    <div class="app-map__stage">
        <div class="app-map__canvas" data-role="map-canvas">
            <!-- SVG 由 App.machineMap 產生 -->
        </div>

        <?php if ($compassPosition !== 'none' && $compassPosition !== 'bar'): ?>
            <!-- 疊在角落的版本。會壓到那一區的機台，確定該角落沒有機器再用。 -->
            <?php View::component('compass', [
                'angle'    => $north,
                'label'    => $compassLabel,
                'format'   => $compassFormat,
                'position' => $compassPosition,
                'size'     => $compassSize ?? 84,
            ]); ?>
        <?php endif; ?>
    </div>
</div>
