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
 *   id        自己指定輸入框的 id。不給就依 name 產（f_keyword），
 *             同一頁有兩個同名欄位時自動接序號（f_keyword_2）
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
 * 同一頁出現第二個同名欄位時，自動幫它換一個 id。
 *
 * 例如權限管理頁上下兩排條件列都有「關鍵字」，兩個輸入框的 id
 * 都會是 f_keyword。id 重複的話 label 的 for 全部指到第一個，點下面那排的
 * label，游標會跳到上面那排的輸入框。這裡只是在重複的那一個後面接序號
 * （f_keyword_2），沒重複的欄位跟自己給 id 的都不受影響。
 *
 * id 只用在 label 的 for 與 radio/checklist 的選項，全站的 JS 都是用
 * name 或 data-role 找欄位的，換號不會打到任何人。
 */
if (!isset($id) && $name !== '') {
    if (!isset($GLOBALS['__app_field_ids'])) {
        $GLOBALS['__app_field_ids'] = [];
    }

    if (isset($GLOBALS['__app_field_ids'][$fieldId])) {
        $GLOBALS['__app_field_ids'][$fieldId]++;
        $fieldId .= '_' . $GLOBALS['__app_field_ids'][$fieldId];
    } else {
        $GLOBALS['__app_field_ids'][$fieldId] = 1;
    }
}

/**
 * 選項陣列正規化。
 * ['A', 'B'] 與 ['A' => 'A 區'] 兩種寫法都接受，
 * 頁面就不用為了「值跟顯示文字一樣」多寫一次。
 *
 * ⚠ 判斷「是不是純值陣列」要看**整包**，不能一個一個看 is_int($key)。
 *
 *   PHP 會把看起來像整數的字串鍵自動轉成整數：
 *   ['1' => '第 1 次'] 的鍵其實是 int(1)，不是 '1'。
 *   用 is_int($key) 判斷的話，這種寫法會被誤認成純值陣列，
 *   <option value> 就會變成顯示文字（value="第 1 次"）。
 *
 *   而「數字當選項值」正是最常見的一種下拉 —— 第幾次、月份、等級、班別。
 *   送出去的變成「第 1 次」這種字串之後，後端 Request::int() 讀不到數字、
 *   條件被靜靜丟掉，畫面上完全沒有錯誤訊息，只是「查詢沒反應」。
 *
 * 所以改成看鍵是不是剛好 0,1,2…（就是 PHP 8.1 的 array_is_list()，
 * 這個專案跑 7.2 所以自己寫）。
 */
$isList = $options === [] || array_keys($options) === range(0, count($options) - 1);

$normalized = [];
foreach ($options as $key => $text) {
    $normalized[] = ($isList && !is_array($text))
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
