<?php
/**
 * 廠內機台平面圖（分頁版）畫面。
 *
 * 一層樓一個頁籤，每張圖靠 params 帶自己的 floor 去查，
 * 而且都沒有設 filter，所以不會跟任何下拉連動——
 * 切頁籤就只是換一張圖，不會互相影響。
 *
 * 第一個頁籤以外都設 lazy + auto=false：
 * 兩張圖都在 DOM 裡（Bootstrap 只是把非目前頁籤藏起來），
 * 不延遲的話一進頁面就同時打兩支 API。
 */

use App\Core\View;

$tabs = [];

foreach ($floors as $i => $floor) {
    $axis = $axes[$floor] ?? $axes['default'];

    $tabs[] = [
        'key'     => strtolower($floor),
        'title'   => $floor,
        'icon'    => 'building',
        'lazy'    => $i > 0,
        'content' => View::componentHtml('machine_map', [
            'id'     => 'floorMap' . $floor,
            'api'    => url('/api/machine/map.php'),
            'axisX'  => $axis['x'],
            'axisY'  => $axis['y'],

            // 這張圖固定只查這一層
            'params' => ['floor' => $floor],

            // 不給 filter：不跟任何下拉連動
            'auto'   => $i === 0,
        ]),
    ];
}
?>
<div class="app-container app-container--wide">

    <?php if ($tabs === []): ?>
        <?php View::component('empty_state', [
            'icon'    => 'building',
            'title'   => '查不到樓層資料',
            'message' => '機台主檔的 floor 欄位是空的，請先補上樓層再回到這一頁。',
        ]); ?>
    <?php else: ?>
        <?php View::component('tabs', ['id' => 'floorTabs', 'tabs' => $tabs]); ?>
    <?php endif; ?>

</div>
