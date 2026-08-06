<?php
/**
 * 每日生產日報 —— 查詢條件欄位。
 *
 * 欄位的 name 會被自動收集成 query string 送給表格的 API，
 * 所以這裡的 name 要跟後端 Request::str('xxx') 取的名字一致。
 */

use App\Core\View;
?>

<?php
/**
 * 日期區間。scope 對應 config/app.php 的 query_range，
 * 決定最多能選幾天；超出範圍的日期在日曆上直接不能點。
 * 後端也會用同一個 scope 再擋一次。
 */
View::component('date_range', [
    'name'    => 'daily_date',
    'label'   => '查詢區間',
    'scope'   => 'report',   // machine_log = 一週 / report = 一個月 / default
    'default' => 7,
]);
?>

<div class="app-field">
    <label class="app-field__label" for="f_daily_type">類別</label>
    <select class="form-select" id="f_daily_type" name="type">
        <option value="">全部</option>
        <option value="A">類別 A</option>
        <option value="B">類別 B</option>
    </select>
</div>

<div class="app-field app-field--grow">
    <label class="app-field__label" for="f_daily_keyword">關鍵字</label>
    <div class="app-field__icon-input">
        <i class="bi bi-search"></i>
        <input type="text" class="form-control" id="f_daily_keyword" name="keyword"
               placeholder="請輸入關鍵字" value="<?= e(old('keyword')) ?>">
    </div>
</div>
