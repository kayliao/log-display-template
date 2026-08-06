<?php
/**
 * 機台狀態總表的查詢條件欄位。
 *
 * 欄位的 name 會被 App.filter 收集起來，
 * 直接當成 query string 送給表格的 API，所以命名要跟後端一致。
 */
?>
<div class="app-field">
    <label class="app-field__label" for="f_area">廠區</label>
    <select class="form-select" id="f_area" name="area">
        <option value="">全部</option>
        <?php foreach ($areas as $area): ?>
            <option value="<?= e($area) ?>" <?= old('area') === $area ? 'selected' : '' ?>>
                <?= e($area) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="app-field">
    <label class="app-field__label" for="f_status">狀態</label>
    <select class="form-select" id="f_status" name="status">
        <option value="">全部</option>
        <option value="RUN">運轉中</option>
        <option value="IDLE">待機</option>
        <option value="DOWN">停機</option>
        <option value="ALARM">異常</option>
        <option value="OFF">關機</option>
    </select>
</div>

<div class="app-field app-field--grow">
    <label class="app-field__label" for="f_keyword">關鍵字</label>
    <div class="app-field__icon-input">
        <i class="bi bi-search"></i>
        <input type="text" class="form-control" id="f_keyword" name="keyword"
               placeholder="機台編號 / 名稱 / 機型" value="<?= e(old('keyword')) ?>">
    </div>
</div>
