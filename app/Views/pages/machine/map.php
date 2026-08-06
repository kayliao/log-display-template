<?php
/**
 * 廠內機台平面圖畫面。
 *
 * 座標系統：橫軸 A、B、C…，縱軸 1、2、3…，
 * 每台機器用「起始座標 + 佔幾格寬高」定位，跟現場的地面標線一致。
 */

use App\Core\View;
?>
<div class="app-container app-container--wide">

    <div class="app-toolbar">
        <div class="app-field">
            <label class="app-field__label" for="f_map_area">廠區</label>
            <select class="form-select" id="f_map_area" name="area">
                <option value="">全部</option>
                <?php foreach ($areas as $area): ?>
                    <option value="<?= e($area) ?>"><?= e($area) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- 狀態統計，由 App.machineMap 載入資料後填入 -->
        <div class="app-statgroup" data-role="map-summary"></div>
    </div>

    <?php
    View::component('machine_map', [
        'id'    => 'shopMap',
        'api'   => url('/api/machine/map.php'),
        'axisX' => range('A', 'L'),
        'axisY' => range(1, 10),
    ]);
    ?>

</div>
