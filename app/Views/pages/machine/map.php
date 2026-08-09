<?php
/**
 * 廠內機台平面圖畫面。
 *
 * 座標系統：橫軸 A、B、C…，縱軸 1、2、3…，
 * 每台機器用「起始座標 + 佔幾格寬高」定位，跟現場的地面標線一致。
 *
 * 版面是「左 1/3 資料、右 2/3 圖」的示範：
 * 分欄交給 split 元件，不要在頁面自己刻 CSS grid。
 */

use App\Core\View;
?>
<div class="app-container app-container--wide">

    <?php
    View::component('split', [
        'ratio'  => '1-2',
        'sticky' => 0,     // 左欄跟著捲動，右邊的圖再長也看得到篩選

        'left'  => View::componentHtml('panel', [
            'title'   => '廠區與狀態',
            'icon'    => 'sliders',
            'content' => View::capture('pages/machine/_map_side', ['areas' => $areas]),
        ]),

        'right' => View::componentHtml('machine_map', [
            'id'     => 'shopMap',
            'api'    => url('/api/machine/map.php'),
            'axisX'  => range('A', 'L'),
            'axisY'  => range(1, 10),

            // 跟左欄的廠區下拉連動。要連動哪一個是設定出來的，
            // 元件不會自己去猜頁面上有哪些篩選器。
            'filter' => '#f_map_area',
        ]),
    ]);
    ?>

</div>
