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
 */

use App\Support\ColumnSet;

$id      = $id ?? ('tbl' . substr(md5(uniqid('', true)), 0, 8));
$set     = ColumnSet::make($columns ?? []);
$rows    = $set->headerRows();
$hasGrp  = $set->hasGroups();

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
];
?>
<div class="app-table" id="<?= e($id) ?>-wrap" data-table-config='<?= e(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>

    <?php if (!empty($title) || !empty($toolbar) || !empty($export)): ?>
        <div class="app-table__bar">
            <?php if (!empty($title)): ?>
                <h3 class="app-table__title"><?= e($title) ?></h3>
            <?php endif; ?>

            <div class="app-table__actions">
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
