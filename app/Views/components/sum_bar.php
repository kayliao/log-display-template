<?php
/**
 * 合計列。
 *
 * 掛在一張可勾選的表格下面，顯示「白片 / 彩片 / 總計」這種分組合計。
 *
 *   View::component('sum_bar', [
 *       'id'     => 'sumE30',
 *       'api'    => url('/api/xxx/summary.php'),
 *       'table'  => 'tableE30',              // 綁哪一張表
 *       'params' => ['station' => 'E30'],       // 固定要帶的參數
 *       'rows'   => [
 *           ['labels' => ['白片', '彩片', '總片數'],
 *            'keys'   => ['white_qty', 'color_qty', 'total_qty']],
 *
 *           ['labels' => ['白片', '彩片', '總盒數'],
 *            'keys'   => ['white_box_qty', 'color_box_qty', 'total_box_qty']],
 *       ],
 *   ]);
 *
 * 為什麼合計要跟後端要，不自己把畫面上的數字加一加：
 * 表格是後端分頁的，前端手上只有當頁那幾十筆，自己加會變成「這一頁的合計」，
 * 翻個頁數字就跳掉。這種錯很難被發現，因為每一頁看起來都很合理。
 *
 * 有勾選時算勾起來的那些，沒勾選時算「這次查到的全部」。
 */

use App\Core\View;

$id   = $id ?? 'sumBar';
$rows = $rows ?? [];

$config = [
    'id'     => $id,
    'api'    => $api ?? null,
    'table'  => $table ?? null,
    'params' => $params ?? [],
    'keys'   => array_map(function ($row) {
        return $row['keys'];
    }, $rows),
];
?>
<div class="app-sum" id="<?= e($id) ?>"
     data-sum-config='<?= e(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>

    <div class="app-sum__bar">
        <span class="app-sum__count">
            已勾選 <strong data-role="sum-count">0</strong> 筆
            <span class="app-sum__hint" data-role="sum-hint">（未勾選時顯示查詢結果的合計）</span>
        </span>

        <span class="app-sum__actions">
            <?php if (!empty($table)): ?>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-role="sum-all">
                    <i class="bi bi-check2-square"></i> 全選查詢結果
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-role="sum-clear">
                    <i class="bi bi-eraser"></i> 清除勾選
                </button>
            <?php endif; ?>
        </span>
    </div>

    <table class="app-sum__table">
        <?php foreach ($rows as $index => $row): ?>
            <tr data-row="<?= (int) $index ?>">
                <?php foreach ($row['labels'] as $col => $label): ?>
                    <th><?= e($label) ?></th>
                    <td data-role="sum-cell" data-row="<?= (int) $index ?>" data-col="<?= (int) $col ?>">－</td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
