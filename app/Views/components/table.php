<?php
/**
 * 報表表格元件。
 *
 * 用法：
 *   View::component('table', [
 *       'id'      => 'machineTable',
 *       'columns' => $columns,                     // ColumnSet 的欄位定義陣列
 *       'api'     => url('/api/machine/list.php'), // 後端分頁 API；不給就是純前端表格
 *       'sort'    => 'machine_id',
 *       'dir'     => 'asc',
 *       'size'    => 50,
 *       'paging'  => true,
 *       'export'  => url('/api/machine/list.php?export=csv'),
 *       'toolbar' => '<button ...>',               // 表格左上角自訂按鈕（HTML 字串）
 *   ]);
 *
 * 表頭在 PHP 這邊就渲染好（含兩層大標小標），
 * 資料則由 App.table 依設定去打 API，
 * 所以「還沒查詢」的時候畫面上就已經看得到完整欄位結構。
 *
 * 要讓使用者勾選資料列時加上 select：
 *
 *   'select' => [
 *       'key' => 'sched_sn',                 // 拿哪一個欄位當識別碼，必填
 *       'ids' => url('/api/xxx/ids.php'),    // 「全選查詢結果」要打的 API（選用）
 *   ]
 *
 * 最左邊會多一個勾選欄，表頭那顆是「全選本頁」。
 * 勾選狀態存在表格實例裡（存的是識別碼，不是畫面上那個 checkbox 元素），
 * 所以換頁、重新排序、重新查詢都不會掉。
 *
 * ids 的 API 要回 { ids: [...] }，內容是「符合目前查詢條件的全部識別碼」。
 * 給了它，工具列才會出現「全選查詢結果」—— 後端分頁的情況下使用者要的
 * 通常是整批查詢結果，而不是剛好在這一頁的那幾十筆；
 * 讓前端翻完所有頁去收集太慢，所以請後端一次給。
 */

use App\Support\ColumnSet;

$id      = $id ?? ('tbl' . substr(md5(uniqid('', true)), 0, 8));
$set     = ColumnSet::make($columns ?? []);
$rows    = $set->headerRows();
$hasGrp  = $set->hasGroups();

// key 沒給就當作沒有勾選欄——沒有識別碼的話勾了也記不住是哪一筆
$select  = (!empty($select) && !empty($select['key'])) ? $select : null;

$config = [
    'id'      => $id,
    'api'     => $api ?? null,
    'columns' => $set->toJs(),
    'sort'    => $sort ?? '',
    'dir'     => $dir ?? 'asc',
    'size'    => $size ?? 50,
    'paging'  => $paging ?? true,
    'auto'    => $auto ?? true,     // 進頁面就自動查一次；設 false 表示要按查詢才載入
    'empty'   => $empty ?? '沒有符合條件的資料',
    'select'  => $select,
];
?>
<div class="app-table" id="<?= e($id) ?>-wrap" data-table-config='<?= e(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>

    <?php if (!empty($title) || !empty($toolbar) || !empty($export) || $select !== null): ?>
        <div class="app-table__bar">
            <?php if (!empty($title)): ?>
                <h3 class="app-table__title"><?= e($title) ?></h3>
            <?php endif; ?>

            <?php if ($select !== null): ?>
                <?php
                /**
                 * 勾選的計數與整批操作。
                 *
                 * 計數不是裝飾：按下「全選查詢結果」之後，畫面上只有這一頁的
                 * checkbox 會變勾，使用者無從得知後面幾百筆到底有沒有被選到。
                 * 一開始是 d-none，勾了第一筆才出現。
                 */
                ?>
                <div class="app-table__select" data-role="select-info" hidden>
                    <span class="app-table__select-count">
                        已勾選 <strong data-role="select-count">0</strong> 筆
                    </span>
                    <button type="button" class="btn btn-link btn-sm app-table__select-clear"
                            data-role="select-clear">取消全選</button>
                </div>
            <?php endif; ?>

            <div class="app-table__actions">
                <?php if ($select !== null && !empty($select['ids'])): ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm"
                            data-role="select-all-matching">
                        <i class="bi bi-check2-square"></i> 全選查詢結果
                    </button>
                <?php endif; ?>

                <?= $toolbar ?? '' ?>
                <?php if (!empty($export)): ?>
                    <a class="btn btn-outline-secondary btn-sm" data-role="export" href="<?= e($export) ?>">
                        <i class="bi bi-file-earmark-arrow-down"></i> 匯出
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="app-table__scroll">
        <table class="table table-hover app-table__el" id="<?= e($id) ?>">
            <thead>
                <?php foreach ($rows as $rowIndex => $headerRow): ?>
                    <tr>
                        <?php
                        /**
                         * 勾選欄的表頭只放在第一列，用 rowspan 吃掉整個表頭高度。
                         *
                         * 這一格一定要由 PHP 渲染：DataTables 是拿最後一列表頭去對欄位的，
                         * 只在 JS 那邊多加一個欄位定義而表頭沒跟著加，欄數就對不上，
                         * 整張表會直接初始化失敗。
                         */
                        if ($select !== null && $rowIndex === 0):
                        ?>
                            <th class="app-th app-th--select" rowspan="<?= count($rows) ?>">
                                <input type="checkbox" class="form-check-input"
                                       data-role="select-page" title="全選本頁">
                            </th>
                        <?php endif; ?>

                        <?php foreach ($headerRow as $col): ?>
                            <?php
                            $attrs = [];
                            if (!empty($col['colspan'])) $attrs[] = 'colspan="' . (int) $col['colspan'] . '"';
                            if (!empty($col['rowspan'])) $attrs[] = 'rowspan="' . (int) $col['rowspan'] . '"';
                            if (!empty($col['width']))   $attrs[] = 'style="width:' . (int) $col['width'] . 'px"';

                            $classes = ['app-th'];
                            if (!empty($col['isGroup']))        $classes[] = 'app-th--group';
                            if (!empty($col['align']))          $classes[] = 'text-' . $col['align'];
                            if (!empty($col['className']))      $classes[] = $col['className'];
                            ?>
                            <th class="<?= e(implode(' ', $classes)) ?>" <?= implode(' ', $attrs) ?>>
                                <span class="app-th__label"><?= e($col['title'] ?? '') ?></span>

                                <?php if (!empty($col['tip'])): ?>
                                    <!-- 欄位說明泡泡：只有設了 tip 的欄位才會出現問號 -->
                                    <i class="bi bi-question-circle app-th__tip"
                                       data-bs-toggle="tooltip"
                                       data-bs-placement="top"
                                       title="<?= e($col['tip']) ?>"></i>
                                <?php endif; ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </thead>
            <tbody>
                <!-- 資料列由 App.table 填入 -->
            </tbody>
        </table>
    </div>
</div>
