<?php
/**
 * 表單欄位（最小的那一顆積木）。
 *
 * 全站所有的輸入框都走這個元件，好處是這些事只寫一次：
 * label 的字級與顏色、必填星號、說明泡泡、錯誤訊息的位置、
 * 以及「查詢條件列」與「編輯表單」用的是同一種長相。
 *
 *   View::component('field', [
 *       'type'  => 'text',            // 見下方支援清單
 *       'name'  => 'machine_id',
 *       'label' => '機台編號',
 *       'value' => 'M-101',
 *   ]);
 *
 * 支援的 type：
 *   text / number / password / email / tel / date / time / datetime-local
 *   textarea   多行文字（可給 rows）
 *   select     下拉（options，可給 empty 當第一個空選項）
 *   radio      單選群組（options）
 *   checkbox   單一勾選框（value=1 表示勾起來）
 *   checklist  多選群組（options，value 給陣列）
 *   switch     開關樣式的勾選框
 *   static     唯讀顯示，不是輸入框（表單裡要顯示既有資料時用）
 *   multi      一次輸入多筆值（逗號、換行、空白都可以分隔）
 *
 * 常用參數：
 *   options   ['A' => 'A 區', 'B' => 'B 區']，也接受 ['A', 'B'] 這種純值陣列
 *   empty     select 的第一個選項文字，例如 '全部'
 *   hint      label 旁邊的小灰標，例如 '最多 7 天'
 *   help      欄位下方的說明文字
 *   tip       label 旁的問號泡泡
 *   icon      輸入框左側的 Bootstrap Icon 名稱，例如 'search'
 *   suffix    輸入框右側的單位文字，例如 '分鐘'
 *   error     錯誤訊息（有給就把框變紅）
 *   required / disabled / readonly
 *   width     'grow'（吃掉剩餘空間）| 'block'（整行）| 數字（px 最小寬度）
 *   attrs     其他要放到輸入元素上的屬性，例如 ['data-role' => 'x']
 */

$type        = $type        ?? 'text';
$name        = $name        ?? '';
$label       = $label       ?? '';
$value       = $value       ?? '';
$options     = $options     ?? [];
$placeholder = $placeholder ?? '';
$hint        = $hint        ?? '';
$help        = $help        ?? '';
$tip         = $tip         ?? '';
$icon        = $icon        ?? '';
$suffix      = $suffix      ?? '';
$error       = $error       ?? '';
$required    = !empty($required);
$disabled    = !empty($disabled);
$readonly    = !empty($readonly);
$width       = $width       ?? '';
$rows        = (int) ($rows ?? 3);
$attrs       = $attrs       ?? [];

// 沒給 id 就依 name 產生一組，label 的 for 才點得到輸入框
$fieldId = $id ?? ('f_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $name !== '' ? $name : uniqid()));

/**
 * 選項陣列正規化。
 * ['A', 'B'] 與 ['A' => 'A 區'] 兩種寫法都接受，
 * 頁面就不用為了「值跟顯示文字一樣」多寫一次。
 */
$normalized = [];
foreach ($options as $key => $text) {
    $normalized[] = is_int($key) && !is_array($text)
        ? ['value' => (string) $text, 'text' => (string) $text]
        : ['value' => (string) $key,  'text' => (string) $text];
}

// 外層 class
$classes = ['app-field'];
if ($width === 'grow')  { $classes[] = 'app-field--grow'; }
if ($width === 'block') { $classes[] = 'app-field--block'; }
if ($error !== '')      { $classes[] = 'app-field--error'; }

$style = is_numeric($width) ? ' style="min-width:' . (int) $width . 'px"' : '';

/** 共用的輸入元素屬性 */
$common = '';
if ($name !== '') { $common .= ' name="' . e($name) . '"'; }
if ($disabled)    { $common .= ' disabled'; }
if ($readonly)    { $common .= ' readonly'; }
if ($required)    { $common .= ' required'; }
if ($placeholder !== '') { $common .= ' placeholder="' . e($placeholder) . '"'; }

foreach ($attrs as $attrKey => $attrValue) {
    $common .= ' ' . e($attrKey) . '="' . e($attrValue) . '"';
}

$controlClass = 'form-control' . ($error !== '' ? ' is-invalid' : '');

// 群組型的欄位（radio / checklist / 單一 checkbox / switch）label 不指向單一元素
$isGroup = in_array($type, ['radio', 'checklist'], true);
?>
<div class="<?= e(implode(' ', $classes)) ?>"<?= $style ?>>

    <?php if ($label !== ''): ?>
        <?php if ($isGroup): ?>
            <span class="app-field__label">
        <?php else: ?>
            <label class="app-field__label" for="<?= e($fieldId) ?>">
        <?php endif; ?>

            <span><?= e($label) ?><?php if ($required): ?><i class="app-field__req">*</i><?php endif; ?></span>

            <?php if ($hint !== ''): ?>
                <span class="app-field__hint"><?= e($hint) ?></span>
            <?php endif; ?>

            <?php if ($tip !== ''): ?>
                <i class="bi bi-question-circle app-field__tip"
                   data-bs-toggle="tooltip" data-bs-placement="top" title="<?= e($tip) ?>"></i>
            <?php endif; ?>

        <?= $isGroup ? '</span>' : '</label>' ?>
    <?php endif; ?>

    <?php if ($type === 'static'): ?>
        <div class="app-field__static"><?= $value === '' || $value === null ? '<span class="app-field__empty">—</span>' : e($value) ?></div>

    <?php elseif ($type === 'multi'): ?>
        <?php
        /**
         * 一次輸入多筆。
         *
         * 底層就是一個 textarea，值原樣送給後端，由 Request::multi() 切開——
         * 前端不做切分，這樣「使用者看到的字串」跟「後端收到的字串」永遠一致，
         * 現場回報「我明明有貼進去」的時候才查得下去。
         *
         * App.multiInput 只負責兩件事：顯示已輸入幾筆、一鍵清空。
         */
        $limit = (int) ($limit ?? 200);
        ?>
        <div class="app-multi" data-role="multi-input" data-limit="<?= $limit ?>">
            <textarea class="<?= e($controlClass) ?> app-multi__input" id="<?= e($fieldId) ?>"
                      rows="<?= (int) ($rows ?? 2) ?>"<?= $common ?>><?= e(is_array($value) ? implode("\n", $value) : $value) ?></textarea>

            <div class="app-multi__foot">
                <span class="app-multi__count" data-role="multi-count"></span>
                <button type="button" class="app-multi__clear" data-role="multi-clear" hidden>清空</button>
            </div>
        </div>

    <?php elseif ($type === 'textarea'): ?>
        <textarea class="<?= e($controlClass) ?>" id="<?= e($fieldId) ?>" rows="<?= $rows ?>"<?= $common ?>><?= e($value) ?></textarea>

    <?php elseif ($type === 'select'): ?>
        <select class="form-select<?= $error !== '' ? ' is-invalid' : '' ?>" id="<?= e($fieldId) ?>"<?= $common ?>>
            <?php if (isset($empty)): ?>
                <option value=""><?= e($empty) ?></option>
            <?php endif; ?>
            <?php foreach ($normalized as $option): ?>
                <option value="<?= e($option['value']) ?>"<?= (string) $value === $option['value'] ? ' selected' : '' ?>>
                    <?= e($option['text']) ?>
                </option>
            <?php endforeach; ?>
        </select>

    <?php elseif ($type === 'radio' || $type === 'checklist'): ?>
        <?php
        $inputType = $type === 'radio' ? 'radio' : 'checkbox';
        $checked   = is_array($value) ? array_map('strval', $value) : [(string) $value];
        ?>
        <div class="app-field__choices<?= !empty($inline) ? ' app-field__choices--inline' : '' ?>">
            <?php foreach ($normalized as $i => $option): ?>
                <?php $optionId = $fieldId . '_' . $i; ?>
                <div class="form-check">
                    <input class="form-check-input" type="<?= $inputType ?>" id="<?= e($optionId) ?>"
                           value="<?= e($option['value']) ?>"
                           <?= in_array($option['value'], $checked, true) ? 'checked' : '' ?>
                           <?= $name !== '' ? 'name="' . e($name) . ($type === 'checklist' ? '[]' : '') . '"' : '' ?>
                           <?= $disabled ? 'disabled' : '' ?>>
                    <label class="form-check-label" for="<?= e($optionId) ?>"><?= e($option['text']) ?></label>
                </div>
            <?php endforeach; ?>
        </div>

    <?php elseif ($type === 'checkbox' || $type === 'switch'): ?>
        <div class="form-check<?= $type === 'switch' ? ' form-switch' : '' ?>">
            <input class="form-check-input" type="checkbox" id="<?= e($fieldId) ?>"
                   value="<?= e($checkedValue ?? '1') ?>"
                   <?= !empty($value) ? 'checked' : '' ?><?= $common ?>>
            <label class="form-check-label" for="<?= e($fieldId) ?>"><?= e($text ?? '') ?></label>
        </div>

    <?php else: ?>
        <?php
        // 有 icon 或單位就多包一層，沒有就直接輸出，HTML 不要無謂地變深。
        // icon 與 suffix 各自獨立，只有 suffix 時不能吃到 icon 的左內距。
        $wrapped = $icon !== '' || $suffix !== '';

        $wrapClass = 'app-field__control';
        if ($icon !== '')   { $wrapClass .= ' app-field__control--icon'; }
        if ($suffix !== '') { $wrapClass .= ' app-field__control--suffix'; }
        ?>
        <?php if ($wrapped): ?>
            <div class="<?= e($wrapClass) ?>">
                <?php if ($icon !== ''): ?><i class="bi bi-<?= e($icon) ?>"></i><?php endif; ?>
        <?php endif; ?>

        <input type="<?= e($type) ?>" class="<?= e($controlClass) ?>" id="<?= e($fieldId) ?>"
               value="<?= e($value) ?>"<?= $common ?>>

        <?php if ($wrapped): ?>
                <?php if ($suffix !== ''): ?><span class="app-field__suffix"><?= e($suffix) ?></span><?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="app-field__error"><i class="bi bi-exclamation-circle"></i> <?= e($error) ?></div>
    <?php elseif ($help !== ''): ?>
        <div class="app-field__help"><?= e($help) ?></div>
    <?php endif; ?>
</div>
