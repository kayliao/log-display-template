<?php
/**
 * 單筆資料直立顯示。
 *
 * 表格是「一筆一列、欄位橫著排」，那是拿來比較很多筆用的。
 * 只看一筆的時候（點開一台機器、打開一張工單）橫著排就很難讀，
 * 這個元件把同一筆資料轉成直立顯示：
 *
 *   機台編號  M-101      機台名稱  A 線 1 號機
 *   機型      CNC-500    廠區      A
 *   製造商    台中精機    安裝日期  2023-04-18
 *
 * 兩欄等寬、由左至右填，跟表格一樣支援「大項底下掛小項」。
 *
 *   View::component('record', [
 *       'title'   => 'M-101 詳細資料',
 *       'columns' => 2,                       // 幾欄，預設 2；窄的地方給 1
 *       'fields'  => [
 *           ['label' => '機台編號', 'value' => 'M-101'],
 *           ['label' => '目前狀態', 'badge' => ['label' => '運轉中', 'status' => 'run']],
 *           ['label' => '備註',     'value' => '……', 'span' => 'full'],
 *
 *           ['title' => '今日產量', 'children' => [
 *               ['label' => '良品', 'value' => 1280, 'format' => 'number'],
 *               ['label' => '不良', 'value' => 32,   'format' => 'number'],
 *           ]],
 *       ],
 *   ]);
 *
 * 層數不限。「稼動率要再分白班／夜班」就是把它寫成一個大項：
 *
 *   ['title' => '稼動率', 'children' => [
 *       ['label' => '白班', 'value' => 82.3, 'format' => 'percent'],
 *       ['label' => '夜班', 'value' => 71.5, 'format' => 'percent'],
 *   ]],
 *
 * 白班底下還要再分就再掛一層，跟表格的多層表頭是同一個概念：
 *
 *   ['title' => '稼動率', 'children' => [
 *       ['title' => '白班', 'children' => [
 *           ['label' => '上半場', 'value' => 85.1, 'format' => 'percent'],
 *           ['label' => '下半場', 'value' => 79.5, 'format' => 'percent'],
 *       ]],
 *       ['title' => '夜班', 'children' => [...]],
 *   ]],
 *
 * 每個欄位可用的鍵：
 *   label   欄位名稱
 *   value   值
 *   format  'number' | 'decimal' | 'percent' | 'datetime' | 'date'
 *   badge   改用徽章顯示（badge 元件的參數），跟 value 二擇一
 *   span    'full' 表示佔滿整列（備註、地址這種長值用）
 *   mono    true 表示用等寬字（編號、時間對齊比較好看）
 *   html    直接放 HTML（要自己逸出）
 *
 * 這個元件也是放大鏡彈窗裡「基本資料」那一段的長相——
 * 前端 app.modal.js 產生的 HTML 跟這裡一模一樣，
 * 所以後端渲染與 API 回傳兩條路看起來完全一致。
 */

use App\Core\View;

$title   = $title   ?? '';
$fields  = $fields  ?? [];
$columns = max(1, (int) ($columns ?? 2));
$flow    = $flow    ?? 'row';     // row = 由左至右填（預設）| column = 先填滿左欄
$variant = $variant ?? '';        // 'plain' = 不要外框（塞進 panel 或彈窗裡用）

/**
 * 值的格式化。跟 stat_card、前端 App.format 是同一套規則。
 */
$fmt = function ($value, $format) {
    if ($value === null || $value === '') {
        return null;                       // 交給呼叫端顯示破折號
    }

    switch ($format) {
        case 'number':   return is_numeric($value) ? number_format((float) $value) : (string) $value;
        case 'decimal':  return is_numeric($value) ? number_format((float) $value, 1) : (string) $value;
        case 'percent':  return is_numeric($value) ? number_format((float) $value, 1) . '%' : (string) $value;
        case 'datetime': return substr(str_replace('T', ' ', (string) $value), 0, 19);
        case 'date':     return substr((string) $value, 0, 10);
        default:         return (string) $value;
    }
};

/**
 * 一格。抽成函式是因為大項底下的小項要用同一份渲染，
 * 不能讓兩個地方各長各的。
 */
$renderItem = function (array $field) use ($fmt) {
    $span  = ($field['span'] ?? '') === 'full' ? ' app-record__item--full' : '';
    $mono  = !empty($field['mono']) ? ' app-record__value--mono' : '';

    echo '<div class="app-record__item' . $span . '">';
    echo '<div class="app-record__label">' . e($field['label'] ?? '') . '</div>';
    echo '<div class="app-record__value' . $mono . '">';

    if (!empty($field['badge'])) {
        View::component('badge', $field['badge']);
    } elseif (isset($field['html'])) {
        echo $field['html'];
    } else {
        $text = $fmt($field['value'] ?? null, $field['format'] ?? null);
        echo $text === null
            ? '<span class="app-record__empty">—</span>'
            : e($text);
    }

    echo '</div></div>';
};

/**
 * 一段欄位。遞迴呼叫自己，所以層數不限——
 * 「稼動率 → 白班／夜班」是兩層，底下要再分「上半場／下半場」就是三層，
 * 一路往下掛 children 就好，跟表格的多層表頭是同一個概念。
 *
 * $level 只影響縮排樣式：第一層的大項用上方橫線分隔，
 * 第二層以後改用左邊的細線內縮，不然層數一多就看不出誰屬於誰。
 */
$renderFields = function (array $fields, int $level = 0) use (&$renderFields, $renderItem) {
    echo '<div class="app-record__grid">';

    foreach ($fields as $field) {
        if (!empty($field['children'])) {
            $groupClass = 'app-record__group' . ($level > 0 ? ' app-record__group--sub' : '');

            echo '<div class="' . $groupClass . '">';
            echo '<div class="app-record__group-title">' . e($field['title'] ?? '') . '</div>';

            $renderFields($field['children'], $level + 1);

            echo '</div>';
            continue;
        }

        $renderItem($field);
    }

    echo '</div>';
};

/**
 * 由上往下填時，要先算出每一欄放幾列，CSS 才知道要在哪裡換欄。
 * 有大項時不套用（大項是整列的，跟直向填法會打架）。
 */
$hasGroups = false;
foreach ($fields as $field) {
    if (!empty($field['children'])) {
        $hasGroups = true;
        break;
    }
}

$gridStyle = '';
if ($flow === 'column' && !$hasGroups && $columns > 1) {
    $gridStyle = ' style="grid-auto-flow: column; grid-template-rows: repeat('
               . (int) ceil(count($fields) / $columns) . ', auto)"';
}
?>
<div class="app-record app-record--cols<?= $columns ?><?= $variant === 'plain' ? ' app-record--plain' : '' ?>">

    <?php if ($title !== ''): ?>
        <div class="app-record__head">
            <?php if (!empty($icon)): ?><i class="bi bi-<?= e($icon) ?>"></i><?php endif; ?>
            <span class="app-record__title"><?= e($title) ?></span>
        </div>
    <?php endif; ?>

    <?php
    if ($gridStyle !== '') {
        // 直向填法沒有大項，直接輸出一層格線就好
        echo '<div class="app-record__grid"' . $gridStyle . '>';
        foreach ($fields as $field) {
            $renderItem($field);
        }
        echo '</div>';
    } else {
        $renderFields($fields);
    }
    ?>
</div>
