<?php
/**
 * 平面圖左欄：廠區選擇 + 狀態統計。
 *
 * 統計數字由 App.machineMap 載完資料後填進 [data-role="map-summary"]，
 * 它是用 document 找這個元素的，所以搬到哪一欄都還是會動。
 */
?>
<div class="app-field app-field--block">
    <label class="app-field__label" for="f_map_area">廠區</label>
    <select class="form-select" id="f_map_area" name="area">
        <option value="">全部</option>
        <?php foreach ($areas as $area): ?>
            <option value="<?= e($area) ?>"><?= e($area) ?></option>
        <?php endforeach; ?>
    </select>
</div>

<h4 class="app-panel__subtitle">機台狀態</h4>

<!-- 狀態統計，由 App.machineMap 載入資料後填入 -->
<div class="app-statgroup app-statgroup--stack" data-role="map-summary">
    <div class="app-panel__hint">載入中…</div>
</div>
