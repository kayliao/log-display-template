<?php
/**
 * 日期區間選擇器。
 *
 * 用法：
 *   View::component('date_range', [
 *       'name'     => 'date',        // 會產生 date_start / date_end 兩個欄位
 *       'label'    => '查詢區間',
 *       'scope'    => 'machine_log', // 對應 config/app.php 的 query_range，決定最多能選幾天
 *       'default'  => 7,             // 預設帶出最近幾天
 *       'maxDate'  => 'today',       // 不能選未來
 *   ]);
 *
 * 區間上限是從後端設定傳給前端的，
 * 使用者在日曆上根本點不到超出範圍的日期（不是選完才跳警告），
 * 同時後端 Request::dateRange() 會再擋一次，避免直接打 API 繞過。
 */

$name    = $name ?? 'date';
$scope   = $scope ?? 'default';
$maxDays = (int) config('app.query_range.' . $scope, config('app.query_range.default', 31));
$default = (int) ($default ?? min(7, $maxDays));

$startValue = old($name . '_start', date('Y-m-d', strtotime('-' . max(0, $default - 1) . ' days')));
$endValue   = old($name . '_end', date('Y-m-d'));

$config = [
    'maxDays' => $maxDays,
    'maxDate' => $maxDate ?? 'today',
    'minDate' => $minDate ?? null,
];
?>
<div class="app-field app-daterange"
     data-daterange-config='<?= e(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>

    <label class="app-field__label" for="<?= e($name) ?>_start">
        <?= e($label ?? '查詢區間') ?>
        <?php if ($maxDays > 0): ?>
            <span class="app-field__hint">最多 <?= $maxDays ?> 天</span>
        <?php endif; ?>
    </label>

    <div class="app-daterange__inputs">
        <div class="app-daterange__input">
            <i class="bi bi-calendar3"></i>
            <input type="text"
                   class="form-control"
                   id="<?= e($name) ?>_start"
                   name="<?= e($name) ?>_start"
                   value="<?= e($startValue) ?>"
                   data-role="range-start"
                   autocomplete="off"
                   placeholder="開始日期">
        </div>

        <span class="app-daterange__sep">～</span>

        <div class="app-daterange__input">
            <i class="bi bi-calendar3"></i>
            <input type="text"
                   class="form-control"
                   id="<?= e($name) ?>_end"
                   name="<?= e($name) ?>_end"
                   value="<?= e($endValue) ?>"
                   data-role="range-end"
                   autocomplete="off"
                   placeholder="結束日期">
        </div>
    </div>

    <!-- 常用區間快捷鍵；超過上限的選項會自動隱藏 -->
    <div class="app-daterange__presets" data-role="presets">
        <button type="button" class="app-chip" data-days="1">今天</button>
        <button type="button" class="app-chip" data-days="7">近 7 天</button>
        <button type="button" class="app-chip" data-days="14">近 14 天</button>
        <button type="button" class="app-chip" data-days="30">近 30 天</button>
    </div>
</div>
