<?php
/**
 * 資訊小卡：一行一個數字，只放最重要的幾項。
 *
 *   View::component('stat_card', [
 *       'title' => 'M-101 今日概況',
 *       'icon'  => 'speedometer2',
 *       'items' => [
 *           ['label' => '稼動率', 'value' => 25,   'unit' => '%', 'tone' => 'danger', 'bar' => 25],
 *           ['label' => '良品',   'value' => 1280, 'format' => 'number'],
 *           ['label' => '不良',   'value' => 32,   'format' => 'number', 'tone' => 'warning'],
 *           ['label' => '狀態',   'badge' => ['label' => '運轉中', 'status' => 'run']],
 *       ],
 *   ]);
 *
 * 這是「一眼掃過去」用的，不是拿來塞完整資料的——
 * 完整的一筆資料請用 record 元件（兩欄直立顯示）。
 *
 * 每一項可用的鍵：
 *   label   左邊的名稱
 *   value   右邊的數字或文字
 *   unit    接在數字後面的單位，例如 '%'、'分鐘'、'台'
 *   format  'number'（千分位）| 'decimal'（小數一位）| 'percent'
 *   tone    success | warning | danger | info | muted，把數字染色
 *   bar     0~100，在該列下方畫一條進度條（稼動率、達成率這種很適合）
 *   delta   跟昨天/上期比的變化，例如 +3.2 或 -1.5，自動配上下箭頭與顏色
 *   hint    數字底下的小字說明
 *   badge   改成顯示徽章（badge 元件的參數），跟 value 二擇一
 *
 * 要讓數字自己更新（匯入完、按查詢之後重抓）就多給 id 與 api，
 * 用法跟 stat_tile 一樣：
 *
 *   View::component('stat_card', [
 *       'id'       => 'aquaCycles',
 *       'items'    => $summary['cycles'],              // 後端先算好的初始值
 *       'subtitle' => $summary['subtitle'],
 *       'api'      => url('/api/hydration/today.php'),
 *       'field'    => 'cycles',                        // 從回應的哪一個鍵取 items（預設 items）
 *       'auto'     => false,                           // 初始值已在畫面上，不用再打一次
 *   ]);
 *
 * 重畫的時候 title / subtitle 只要回應裡有就一起換掉，所以「統計日期」
 * 這種會跟著資料變的字要放 subtitle，不要寫死在卡片外面。
 */

use App\Core\View;

$title   = $title   ?? '';
$icon    = $icon    ?? '';
$items   = $items   ?? [];
$footer  = $footer  ?? '';
$variant = $variant ?? '';   // 'plain' = 不要外框，塞進 panel 裡面時用

$id     = $id     ?? '';
$api    = $api    ?? '';
$field  = $field  ?? 'items';
$params = $params ?? [];

/**
 * 數字格式化。跟前端 App.format 是同一套規則，
 * 同一個數字在後端渲染與前端渲染看起來要一樣。
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

// 沒給 api 就是一張靜態的小卡，什麼設定都不用輸出，前端也不會去認它
$config = $api === '' ? null : [
    'id'     => $id,
    'kind'   => 'card',
    'api'    => $api,
    'field'  => $field,
    'params' => (object) $params,
    'auto'   => !isset($auto) || $auto !== false,
];
?>
<div class="app-statcard<?= $variant === 'plain' ? ' app-statcard--plain' : '' ?>"
     <?php if ($id !== ''): ?>id="<?= e($id) ?>"<?php endif; ?>
     <?php if ($config !== null): ?>data-stat-config='<?= e(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'<?php endif; ?>>

    <?php if ($title !== ''): ?>
        <div class="app-statcard__head">
            <?php if ($icon !== ''): ?><i class="bi bi-<?= e($icon) ?>"></i><?php endif; ?>
            <span class="app-statcard__title" data-role="stat-title"><?= e($title) ?></span>
            <?php
            /**
             * 有 api 的卡片就算這次沒有副標也要留著這個空的 span，
             * 重畫時才有地方寫。沒有的話「統計日期」會在第一次重抓之後消失。
             */
            ?>
            <?php if (!empty($subtitle) || $config !== null): ?>
                <span class="app-statcard__subtitle" data-role="stat-subtitle"><?= e($subtitle ?? '') ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="app-statcard__list" data-role="stat-list">
        <?php foreach ($items as $item): ?>
            <?php
            $tone = !empty($item['tone']) ? ' app-statcard__value--' . preg_replace('/[^a-z]/', '', $item['tone']) : '';
            $bar  = $item['bar'] ?? null;
            ?>
            <div class="app-statcard__item">
                <div class="app-statcard__row">
                    <span class="app-statcard__label"><?= e($item['label'] ?? '') ?></span>

                    <span class="app-statcard__value<?= $tone ?>">
                        <?php if (!empty($item['badge'])): ?>
                            <?php View::component('badge', $item['badge']); ?>
                        <?php else: ?>
                            <?= e($fmt($item['value'] ?? null, $item['format'] ?? null)) ?><?php
                            if (!empty($item['unit'])): ?><i class="app-statcard__unit"><?= e($item['unit']) ?></i><?php endif; ?>

                            <?php if (isset($item['delta']) && $item['delta'] !== '' && $item['delta'] !== null): ?>
                                <?php
                                $delta = (float) $item['delta'];
                                // 上升不一定是好事（不良率上升是壞事），所以只表示方向，不預設好壞
                                $arrow = $delta > 0 ? 'arrow-up' : ($delta < 0 ? 'arrow-down' : 'dash');
                                ?>
                                <i class="app-statcard__delta app-statcard__delta--<?= $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat') ?>">
                                    <i class="bi bi-<?= $arrow ?>"></i><?= e(ltrim(number_format(abs($delta), 1), '')) ?>
                                </i>
                            <?php endif; ?>
                        <?php endif; ?>
                    </span>
                </div>

                <?php if (!empty($item['hint'])): ?>
                    <div class="app-statcard__hint"><?= e($item['hint']) ?></div>
                <?php endif; ?>

                <?php if ($bar !== null): ?>
                    <?php $pct = max(0, min(100, (float) $bar)); ?>
                    <div class="app-statcard__bar">
                        <span class="app-statcard__bar-fill<?= $tone ? ' app-statcard__bar-fill--' . preg_replace('/[^a-z]/', '', $item['tone']) : '' ?>"
                              style="width: <?= e((string) $pct) ?>%"></span>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($footer !== ''): ?>
        <div class="app-statcard__foot"><?= $footer ?></div>
    <?php endif; ?>
</div>
