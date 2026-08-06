<?php
/**
 * 廠內機台平面圖。
 *
 *   View::component('machine_map', [
 *       'id'      => 'shopMap',
 *       'api'     => url('/api/machine/map.php'),
 *       'axisX'   => range('A', 'L'),      // 橫軸標籤
 *       'axisY'   => range(1, 10),         // 縱軸標籤
 *       'legend'  => [...],                // 狀態 => 顏色說明
 *   ]);
 *
 * 圖形本身用 SVG 由 App.machineMap 畫出來，沒有引入任何繪圖套件——
 * 需求很單純（座標定位的矩形 + 文字），自己畫反而好維護，
 * 也少一個要離線佈署的相依。
 *
 * API 回傳的每台機器：
 *   { "id":"M-101", "model":"CNC-500", "x":"A", "y":3, "w":2, "h":1, "status":"RUN" }
 *   x 是橫軸代號、y 是縱軸號碼、w/h 是佔幾格（機台長寬）。
 */

$id     = $id ?? 'machineMap';
$axisX  = $axisX ?? range('A', 'J');
$axisY  = $axisY ?? range(1, 8);

// 狀態顏色。跟 app.css 的 --status-* 變數對應，改色只要改一個地方。
$legend = $legend ?? [
    'RUN'   => ['label' => '運轉中', 'color' => 'var(--status-run)'],
    'IDLE'  => ['label' => '待機',   'color' => 'var(--status-idle)'],
    'DOWN'  => ['label' => '停機',   'color' => 'var(--status-down)'],
    'ALARM' => ['label' => '異常',   'color' => 'var(--status-alarm)'],
    'OFF'   => ['label' => '關機',   'color' => 'var(--status-off)'],
];

$config = [
    'id'     => $id,
    'api'    => $api ?? null,
    'axisX'  => array_values($axisX),
    'axisY'  => array_values($axisY),
    'legend' => $legend,
    'cell'   => $cell ?? 88,     // 一格的像素大小
    'gap'    => $gap ?? 6,
];
?>
<div class="app-map" id="<?= e($id) ?>-wrap"
     data-map-config='<?= e(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>

    <div class="app-map__bar">
        <div class="app-map__legend">
            <?php foreach ($legend as $status => $meta): ?>
                <span class="app-map__legend-item">
                    <i class="app-map__swatch" style="background: <?= e($meta['color']) ?>"></i>
                    <?= e($meta['label']) ?>
                </span>
            <?php endforeach; ?>
        </div>

        <div class="app-map__tools">
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
        </div>
    </div>

    <div class="app-map__canvas" data-role="map-canvas">
        <!-- SVG 由 App.machineMap 產生 -->
    </div>
</div>
