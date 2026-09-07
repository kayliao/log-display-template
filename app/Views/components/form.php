<?php
/**
 * 表單。
 *
 * 給一份「欄位定義」就長出整張表單，跟 table 元件是同一個想法：
 * 版面、欄位間距、必填星號、按鈕列的位置全部由元件決定，
 * 頁面只描述「有哪些欄位」。
 *
 *   View::component('form', [
 *       'id'      => 'machineForm',
 *       'columns' => 2,                        // 一列放幾欄，預設 2
 *       'sections' => [
 *           ['title' => '基本資料', 'icon' => 'info-circle', 'fields' => [
 *               // 這一段的欄位……
 *               ['name' => 'machine_id',   'label' => '機台編號', 'required' => true],
 *               ['name' => 'machine_name', 'label' => '機台名稱'],
 *               ['name' => 'remark', 'label' => '備註', 'type' => 'textarea', 'span' => 'full'],
 *           ]],
 *       ],
 *       'actions' => [
 *           ['label' => '取消'],
 *           ['label' => '儲存', 'variant' => 'primary', 'type' => 'submit'],
 *       ],
 *   ]);
 *
 * 每個 field 就是 field 元件的參數，多一個 'span' => 'full' 表示佔滿整列
 * （備註、說明這種長欄位用）。
 *
 * 只有一組欄位、不需要分段時可以直接給 'fields' => [...]，不用包 sections。
 *
 * ── 某一段要用不一樣的欄數 ──────────────────────────────────
 *
 * 在那一段自己加一個 columns，只影響那一段：
 *
 *   ['title' => 'INK', 'columns' => 2, 'fields' => [...]]
 *
 * 用在「欄位其實是成對的」那種段落。整張表單三欄、而某一段是
 * 鋼板式樣1／油墨1／鋼板式樣2／油墨2……這樣排下去的話，
 * 三欄會把每一對拆開（第一列變成 鋼板1、油墨1、鋼板2），
 * 現場對欄位要一路數過去。那一段改成兩欄，一對就剛好一列。
 */

use App\Core\View;

$id       = $id      ?? ('form' . substr(md5(uniqid('', true)), 0, 8));
$columns  = (int) ($columns ?? 2);
$method   = strtolower($method ?? 'post');
$action   = $action  ?? '';
$actions  = $actions ?? [];
$sections = $sections ?? [];

// 沒分段的簡寫：'fields' => [...]
if ($sections === [] && !empty($fields)) {
    $sections = [['fields' => $fields]];
}
?>
<form class="app-form" id="<?= e($id) ?>" method="<?= e($method) ?>"
      <?= $action !== '' ? 'action="' . e($action) . '"' : '' ?>
      <?= !empty($autocomplete) ? '' : 'autocomplete="off"' ?>>

    <?php foreach ($sections as $section): ?>
        <div class="app-form__section">
            <?php if (!empty($section['title'])): ?>
                <h4 class="app-form__legend">
                    <?php if (!empty($section['icon'])): ?>
                        <i class="bi bi-<?= e($section['icon']) ?>"></i>
                    <?php endif; ?>
                    <?= e($section['title']) ?>
                </h4>
            <?php endif; ?>

            <?php if (!empty($section['description'])): ?>
                <p class="app-form__desc"><?= e($section['description']) ?></p>
            <?php endif; ?>

            <?php
            /**
             * 這一段要幾欄。沒指定就跟整張表單一樣。
             * 只認 1~4（CSS 只有這四個格線類別），超出範圍就退回表單的設定。
             */
            $cols = (int) ($section['columns'] ?? $columns);

            if ($cols < 1 || $cols > 4) {
                $cols = $columns;
            }
            ?>
            <div class="app-form__grid app-form__grid--<?= $cols ?>">
                <?php foreach ($section['fields'] ?? [] as $field): ?>
                    <?php
                    // span: full = 佔滿整列，數字 = 佔幾欄（不能超過這一段的欄數）
                    $span  = $field['span'] ?? 1;
                    $style = $span === 'full'
                        ? 'grid-column: 1 / -1'
                        : 'grid-column: span ' . min((int) $span, $cols);

                    unset($field['span']);
                    $field['width'] = 'block';   // 在格線裡一律撐滿自己那一格
                    ?>
                    <div class="app-form__cell" style="<?= e($style) ?>">
                        <?php View::component('field', $field); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (!empty($content)): ?>
        <?= $content ?>
    <?php endif; ?>

    <?php if ($actions !== []): ?>
        <div class="app-form__actions">
            <?php View::component('button_group', [
                'align'   => $actionsAlign ?? 'right',
                'buttons' => $actions,
            ]); ?>
        </div>
    <?php endif; ?>
</form>
