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
 * 'blank' => true 表示**預設不帶日期**（兩格都空的），
 * 給「選填的第二組日期條件」用 —— 例如水化排程頁的「水化日期」：
 * 那一欄要等機台取號才有值，預設帶日期的話剛上傳的資料一律查不到。
 * 這種模式會多一顆「不限」把日期清掉。

 * ⚙ `scope` 是「查詢區間上限的設定鍵」，跟 filter_bar 的 `scope`
 *   （網址上的分組名稱）是兩件不同的事。一頁兩排條件列、這一排又有
 *   日期區間的時候，把條件列的分組名稱用 `filterScope` 傳進來，
 *   日期才會跟同一排的其他欄位記在同一組網址參數裡：
 *
 *       View::component('date_range', ['name' => 'date', 'scope' => 'report',
 *                                      'filterScope' => $scope]);   // ?account[date_start]=...
 *
 * 區間上限是從後端設定傳給前端的，
 * 使用者在日曆上根本點不到超出範圍的日期（不是選完才跳警告），
 * 同時後端 Request::dateRange() 會再擋一次，避免直接打 API 繞過。
 */

$name    = $name ?? 'date';
$scope   = $scope ?? 'default';
$maxDays = (int) config('app.query_range.' . $scope, config('app.query_range.default', 31));
$default = (int) ($default ?? min(7, $maxDays));

// blank：預設兩格都空的（選填的條件用），使用者自己挑日期才會生效
$blank = !empty($blank);

// 條件列的分組名稱（不是上面那個 $scope，說明見檔頭）
$filterScope = $filterScope ?? '';

/**
 * 預設值先算好再取值，不要把三元運算包在 old() 外面——
 * old() 的第二個參數會被登記成「清除」要還原的值，
 * 兩種寫法各呼叫一次的話，會有一次登記到錯的預設值。
 *
 * blank 的頁面登記進去的就是空字串，所以按清除是把日期清掉，
 * 而不是還原成一段使用者從來沒選過的區間。
 */
$startDefault = $blank ? '' : date('Y-m-d', strtotime('-' . max(0, $default - 1) . ' days'));
$endDefault   = $blank ? '' : date('Y-m-d');

$startValue = old($name . '_start', $startDefault, $filterScope);
$endValue   = old($name . '_end',   $endDefault,   $filterScope);

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

    <!--
      常用區間快捷鍵，放在日期輸入框下方；超過上限的選項會自動隱藏。

      這一列會讓「查詢區間」比隔壁的欄位高一截，但不影響對齊——
      查詢條件列是頂端對齊（.app-filter 的 align-items: flex-start），
      每個欄位的 label 與控制項各自從同一條線開始，
      比較高的欄位只是往下多長一段，不會把自己的 label 往上推。
    -->
    <div class="app-daterange__presets" data-role="presets">
        <button type="button" class="app-chip" data-days="1">今天</button>
        <button type="button" class="app-chip" data-days="7">近 7 天</button>
        <button type="button" class="app-chip" data-days="14">近 14 天</button>
        <button type="button" class="app-chip" data-days="30">近 30 天</button>

        <?php if ($blank): ?>
            <!-- 選填的條件才有「不限」：按下去把兩格清空，等於不帶這個條件 -->
            <button type="button" class="app-chip" data-role="clear">不限</button>
        <?php endif; ?>
    </div>
</div>
