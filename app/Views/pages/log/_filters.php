<?php
/**
 * 機台 Log 查詢條件。
 *
 * 日期區間用 scope = machine_log，
 * 對應 config/app.php 設定的「最多查一週」，
 * 使用者在日曆上根本點不到第八天，後端也會再擋一次。
 */

use App\Core\View;
?>
<?php View::component('date_range', [
    'name'    => 'log_date',
    'label'   => '查詢區間',
    'scope'   => 'machine_log',
    'default' => 1,
]); ?>

<div class="app-field">
    <label class="app-field__label" for="f_log_area">廠區</label>
    <select class="form-select" id="f_log_area" name="area">
        <option value="">全部</option>
        <?php foreach ($areas as $area): ?>
            <option value="<?= e($area) ?>"><?= e($area) ?></option>
        <?php endforeach; ?>
    </select>
</div>

<div class="app-field">
    <label class="app-field__label" for="f_event_type">事件類型</label>
    <select class="form-select" id="f_event_type" name="event_type">
        <option value="">全部</option>
        <option value="ALARM">警報</option>
        <option value="ERROR">錯誤</option>
        <option value="WARN">警告</option>
        <option value="INFO">一般</option>
        <option value="OP">操作</option>
    </select>
</div>

<div class="app-field">
    <label class="app-field__label" for="f_machine_ids">機台</label>
    <input type="text" class="form-control" id="f_machine_ids" name="machine_ids"
           placeholder="多台請用逗號分隔">
</div>

<div class="app-field app-field--grow">
    <label class="app-field__label" for="f_log_keyword">關鍵字</label>
    <div class="app-field__icon-input">
        <i class="bi bi-search"></i>
        <input type="text" class="form-control" id="f_log_keyword" name="keyword"
               placeholder="訊息內容 / 事件代碼">
    </div>
</div>
