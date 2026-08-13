<?php
/**
 * 數字小卡（stat_tile）。
 *
 * 一個數字一張小卡，橫著排、寬度只吃自己需要的那麼多，
 * 沒有進度條、沒有比較值 —— 就是把幾個關鍵數字擺在頁面最上面。
 *
 *   View::component('stat_tile', [
 *       'items' => [
 *           ['label' => '今日產量', 'value' => 15770, 'unit' => '片', 'format' => 'number'],
 *           ['label' => '達成率',   'value' => 89.6,  'format' => 'percent', 'tone' => 'danger'],
 *           ['label' => '運轉中',   'value' => 32,    'unit' => '台', 'tone' => 'success'],
 *       ],
 *   ]);
 *
 * 只有一個數字的時候不用包 items，直接寫：
 *
 *   View::component('stat_tile', ['label' => '今日產量', 'value' => 15770, 'unit' => '片']);
 *
 * 跟另外兩個很像的元件怎麼選：
 *
 *   stat_tile    一張卡一個數字，橫著排。給「頁面最上面那排關鍵數字」用。
 *   stat_card    一張卡裡好幾個數字（一行一個），可以有進度條與變化量。
 *   achievement  預計 vs 實際 vs 達成率，會自己算合計與佔比。
 *
 * 每一項可用的鍵：
 *   label   卡片上方的名稱
 *   value   數字或文字
 *   unit    接在數字後面的單位，例如 '片'、'台'、'%'
 *   format  'number'（千分位）| 'decimal'（小數一位）| 'percent'
 *   tone    success | warning | danger | info | muted，把數字染色
 *   icon    Bootstrap Icon 名稱（不含 bi- 前綴），放在名稱左邊
 *   hint    數字底下的小字說明
 *   badge   改成顯示徽章（badge 元件的參數），跟 value 二擇一
 *   url     給了就整張卡可以點，連到那一頁（長相完全一樣，滑過去才會有反應）
 *
 * 整體參數：
 *   min      每張卡的最小寬度（px，預設 148）。數字很長時調大一點。
 *   align    'left'（預設）| 'center'
 *   variant  'plain' = 不要外框（塞進 panel 裡面時用）
 */

use App\Core\View;

$items   = $items   ?? null;
$min     = (int) ($min ?? 148);
$align   = ($align ?? 'left') === 'center' ? 'center' : 'left';
$variant = $variant ?? '';

/**
 * 沒給 items 就把參數本身當成一項。
 * 只放一個數字的時候不用為了一顆卡片多包一層陣列。
 */
if ($items === null) {
    $items = [[
        'label'  => $label  ?? '',
        'value'  => $value  ?? null,
        'unit'   => $unit   ?? '',
        'format' => $format ?? null,
        'tone'   => $tone   ?? '',
        'icon'   => $icon   ?? '',
        'hint'   => $hint   ?? '',
        'url'    => $url    ?? '',
        'badge'  => $badge  ?? null,
    ]];
}

/**
 * 數字格式化。跟 stat_card、前端 App.format 是同一套規則，
 * 同一個數字在哪裡出現都要長得一樣。
 */
$fmt = function ($value, $format) {
    if ($value === null || $value === '') {
        return '—';
    }

    switch ($format) {
        case 'number':  return is_numeric($value) ? number_format((float) $value) : (string) $value;
        case 'decimal': return is_numeric($value) ? number_format((float) $value, 1) : (string) $value;
        case 'percent': return is_numeric($value) ? number_format((float) $value, 1) . '%' : (string) $value;
        default:        return (string) $value;
    }
};
?>
<div class="app-tiles<?= $variant === 'plain' ? ' app-tiles--plain' : '' ?> app-tiles--<?= e($align) ?>"
     style="--tile-min: <?= e((string) $min) ?>px">

    <?php foreach ($items as $item): ?>
        <?php
        $tone = !empty($item['tone'])
            ? ' app-tile__value--' . preg_replace('/[^a-z]/', '', $item['tone'])
            : '';

        // 給了 url 就整張卡可以點。長相不變——使用者不需要知道背後是 <a> 還是 <div>
        $url = $item['url'] ?? '';
        $tag = $url !== '' ? 'a' : 'div';
        ?>
        <<?= $tag ?> class="app-tile<?= $url !== '' ? ' app-tile--link' : '' ?>"
            <?php if ($url !== ''): ?>href="<?= e($url) ?>"<?php endif; ?>>

            <div class="app-tile__label">
                <?php if (!empty($item['icon'])): ?>
                    <i class="bi bi-<?= e($item['icon']) ?>"></i>
                <?php endif; ?>
                <span><?= e($item['label'] ?? '') ?></span>
            </div>

            <div class="app-tile__value<?= $tone ?>">
                <?php if (!empty($item['badge'])): ?>
                    <?php View::component('badge', $item['badge']); ?>
                <?php else: ?>
                    <?= e($fmt($item['value'] ?? null, $item['format'] ?? null)) ?><?php
                    if (!empty($item['unit'])): ?><i class="app-tile__unit"><?= e($item['unit']) ?></i><?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if (!empty($item['hint'])): ?>
                <div class="app-tile__hint"><?= e($item['hint']) ?></div>
            <?php endif; ?>
        </<?= $tag ?>>
    <?php endforeach; ?>
</div>
