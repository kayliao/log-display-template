<?php
/**
 * 達成率統整卡（achievement）。
 *
 * 「今天這個排程，預計做多少、實際做多少、達成率多少」——
 * 一張卡把分類明細與合計放在一起，現場站在螢幕前三秒就要看懂。
 *
 *   View::component('achievement', [
 *       'id'       => 'hydrationAchv',
 *       'title'    => '水化排程達成',
 *       'subtitle' => '2026-08-12（今日）',
 *       'icon'     => 'droplet-half',
 *       'unit'     => '片',
 *       'items'    => [
 *           ['label' => '白片', 'plan' => 12000, 'actual' => 11350],
 *           ['label' => '彩片', 'plan' => 6000,  'actual' => 5210],
 *       ],
 *   ]);
 *
 * 合計不用自己算，元件會把 items 加起來：
 *   總預計 = Σplan、總實際 = Σactual、總達成率 = 總實際 ÷ 總預計，
 *   每一項再算出「佔實際的百分比」（白片佔幾成、彩片佔幾成）。
 *   自己算一份傳進來的話，遲早會出現「上面幾項加起來不等於下面的合計」。
 *
 * 參數：
 *   items       每一項：label / plan / actual，另可給 color（#rrggbb）與 hint
 *   unit        數量單位，例如 '片'、'公斤'、'箱'
 *   target      達成率門檻，達到就是綠色（預設 100）
 *   warn        警戒門檻，低於 target 但達到這個數字是黃色，再低是紅色（預設 90）
 *   share       佔比的基準：'actual'（預設，佔實際）或 'plan'（佔預計）
 *   summary     合計放上面還是下面：'top'（預設）或 'bottom'
 *   totalLabel  合計那一塊的名稱（預設「總達成率」）
 *   footer      卡片底部的小字
 *   variant     'plain' = 不要外框（塞進 panel 裡面時用）
 *
 * 要讓卡片跟著查詢條件重查（今日／昨日、換一個排程）就給 api：
 *
 *   View::component('achievement', [
 *       'id'     => 'hydrationAchv',
 *       'api'    => url('/api/report/schedule_summary.php'),
 *       'params' => ['schedule' => 'HYD'],   // 每次都帶的固定參數
 *       'auto'   => true,                    // 一載入就查（掛在查詢條件列下時會自動改由條件列觸發）
 *   ]);
 *
 *   // 查詢條件列把卡片跟表格一起指定，按一次查詢兩邊同時更新
 *   View::component('filter_bar', ['target' => 'hydrationAchv,scheduleTable', ...]);
 *
 * API 回傳 { items: [...], title?, subtitle?, footer? }，
 * 前端 app.achievement.js 會畫出跟這裡一模一樣的結構（跟 record／彈窗同樣的做法）。
 * 兩邊的算法要一致：達成率、佔比、門檻顏色都只有「Σ 之後再除」這一種算法。
 */

$id         = $id         ?? 'achievement';
$title      = $title      ?? '';
$subtitle   = $subtitle   ?? '';
$icon       = $icon       ?? 'bullseye';
$items      = $items      ?? [];
$unit       = $unit       ?? '';
$target     = (float) ($target ?? 100);
$warn       = (float) ($warn   ?? 90);
$share      = ($share   ?? 'actual') === 'plan' ? 'plan' : 'actual';
$summaryPos = ($summary ?? 'top') === 'bottom' ? 'bottom' : 'top';
$totalLabel = $totalLabel ?? '總達成率';
$footer     = $footer     ?? '';
$variant    = $variant    ?? '';
$api        = $api        ?? '';
$empty      = $empty      ?? '沒有可以統計的資料';

/**
 * 分類顏色。
 * 沒指定 color 就依順序取用，前六項不會撞色；第七項起循環。
 * 前端 app.achievement.js 用同一組色碼，重查之後顏色不會跳掉。
 */
$palette = ['#2563eb', '#7c3aed', '#0891b2', '#d97706', '#16a34a', '#db2777'];

/** 只允許色碼，其他一律退回預設色（這個值會進 inline style） */
$safeColor = function ($value, string $fallback) {
    return (is_string($value) && preg_match('/^#[0-9A-Fa-f]{3,8}$/', $value)) ? $value : $fallback;
};

$toNumber = function ($value): ?float {
    return ($value === null || $value === '' || !is_numeric($value)) ? null : (float) $value;
};

/**
 * 數量：整數就不顯示小數點，有小數才留一位。
 * 現場的數字幾乎都是整數，硬要補 .0 反而難讀。
 */
$fmtQty = function (?float $value): string {
    if ($value === null) {
        return '—';
    }

    return abs($value - round($value)) < 0.05
        ? number_format($value)
        : number_format($value, 1);
};

$fmtRate = function (?float $rate): string {
    return $rate === null ? '—' : number_format($rate, 1) . '%';
};

/**
 * 達成率決定顏色。沒有排程（預計 0）就不評價，用灰色。
 */
$toneOf = function (?float $rate) use ($target, $warn): string {
    if ($rate === null) {
        return 'muted';
    }

    if ($rate >= $target) {
        return 'success';
    }

    return $rate >= $warn ? 'warning' : 'danger';
};

// --- 計算 -----------------------------------------------------------------
$rows      = [];
$sumPlan   = 0.0;
$sumActual = 0.0;

foreach ($items as $i => $item) {
    $plan   = $toNumber($item['plan']   ?? null);
    $actual = $toNumber($item['actual'] ?? null);

    $sumPlan   += (float) $plan;
    $sumActual += (float) $actual;

    $rows[] = [
        'label'  => (string) ($item['label'] ?? ''),
        'hint'   => (string) ($item['hint'] ?? ''),
        'color'  => $safeColor($item['color'] ?? null, $palette[$i % count($palette)]),
        'plan'   => $plan,
        'actual' => $actual,
        // 預計是 0 的時候不算達成率——0 個目標做出 100 個，達成率不是 100% 也不是無限大
        'rate'   => ($plan !== null && $plan > 0) ? ((float) $actual / $plan * 100) : null,
        'diff'   => ($plan === null && $actual === null) ? null : ((float) $actual - (float) $plan),
    ];
}

$base = $share === 'plan' ? $sumPlan : $sumActual;

foreach ($rows as $i => $row) {
    $value = $share === 'plan' ? $row['plan'] : $row['actual'];

    $rows[$i]['share'] = $base > 0 ? ((float) $value / $base * 100) : null;
}

$totalRate = $sumPlan > 0 ? ($sumActual / $sumPlan * 100) : null;
$totalTone = $toneOf($totalRate);
$totalDiff = $sumActual - $sumPlan;
$shareText = $share === 'plan' ? '佔預計' : '佔實際';

$config = [
    'id'      => $id,
    'api'     => $api,
    'params'  => $params ?? [],
    'auto'    => ($auto ?? true) !== false,
    'unit'    => $unit,
    'target'  => $target,
    'warn'    => $warn,
    'share'   => $share,
    'summary' => $summaryPos,
    'totalLabel' => $totalLabel,
    'empty'   => $empty,
];

// 合計區塊與明細區塊各自產生，最後再依 summary 決定誰在上面
ob_start();
?>
    <div class="app-achv__total">
        <div class="app-achv__total-head">
            <span class="app-achv__total-label"><?= e($totalLabel) ?></span>

            <span class="app-achv__total-rate app-achv__total-rate--<?= e($totalTone) ?>">
                <?= e($fmtRate($totalRate)) ?>
            </span>

            <?php if ($sumPlan > 0): ?>
                <span class="app-achv__total-gap">
                    <?php if ($totalDiff < 0): ?>
                        還差 <?= e($fmtQty(abs($totalDiff))) ?><?= e($unit) ?>
                    <?php elseif ($totalDiff > 0): ?>
                        超出 <?= e($fmtQty($totalDiff)) ?><?= e($unit) ?>
                    <?php else: ?>
                        剛好達標
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </div>

        <?php
        /**
         * 進度條。
         * 超過 100% 的部分不畫出去（條子最長就是滿格），
         * 實際數字仍然照實顯示在上面——現場要知道有沒有超前，但版面不能被撐爛。
         */
        $totalWidth = $totalRate === null ? 0 : max(0, min(100, $totalRate));
        ?>
        <div class="app-achv__meter">
            <span class="app-achv__meter-fill app-achv__meter-fill--<?= e($totalTone) ?>"
                  style="width: <?= e((string) round($totalWidth, 2)) ?>%"></span>
        </div>

        <?php
        // 單位跟著數字走（17,600 片），不另外開一格寫「單位：片」——
        // 那一格看起來也像一個數據，現場會多花半秒判斷它是什麼
        $unitTag = $unit === '' ? '' : '<i class="app-achv__unit">' . e($unit) . '</i>';
        ?>
        <div class="app-achv__total-nums">
            <div class="app-achv__cell">
                <span class="app-achv__cell-label">總預計</span>
                <b><?= e($fmtQty($sumPlan)) . $unitTag ?></b>
            </div>
            <div class="app-achv__cell">
                <span class="app-achv__cell-label">總實際</span>
                <b><?= e($fmtQty($sumActual)) . $unitTag ?></b>
            </div>
            <div class="app-achv__cell">
                <span class="app-achv__cell-label">差異</span>
                <b class="app-achv__diff app-achv__diff--<?= $totalDiff < 0 ? 'down' : ($totalDiff > 0 ? 'up' : 'flat') ?>">
                    <?= $totalDiff > 0 ? '+' : '' ?><?= e($fmtQty($totalDiff)) . $unitTag ?>
                </b>
            </div>
        </div>

        <?php
        /**
         * 組成比例。
         * 只有兩項以上才畫——一項的時候整條都是它，沒有資訊量。
         */
        $mixable = count($rows) > 1 && $base > 0;
        ?>
        <?php if ($mixable): ?>
            <div class="app-achv__mix">
                <div class="app-achv__mix-bar">
                    <?php foreach ($rows as $row): ?>
                        <?php if ($row['share'] === null || $row['share'] <= 0) { continue; } ?>
                        <span class="app-achv__mix-seg"
                              style="width: <?= e((string) round($row['share'], 2)) ?>%; background: <?= e($row['color']) ?>"
                              title="<?= e($row['label'] . ' ' . $fmtRate($row['share'])) ?>"></span>
                    <?php endforeach; ?>
                </div>

                <div class="app-achv__legend">
                    <span class="app-achv__legend-title"><?= e($shareText) ?></span>
                    <?php foreach ($rows as $row): ?>
                        <span class="app-achv__legend-item">
                            <i class="app-achv__dot" style="background: <?= e($row['color']) ?>"></i>
                            <?= e($row['label']) ?>
                            <b><?= e($fmtRate($row['share'])) ?></b>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
<?php
$totalHtml = ob_get_clean();

ob_start();
?>
    <?php if ($rows === []): ?>
        <div class="app-achv__empty"><?= e($empty) ?></div>
    <?php else: ?>
        <div class="app-achv__list">
            <div class="app-achv__row app-achv__row--head">
                <div class="app-achv__name">項目</div>
                <div class="app-achv__cell"><span>預計</span></div>
                <div class="app-achv__cell"><span>實際</span></div>
                <div class="app-achv__cell"><span>差異</span></div>
                <div class="app-achv__cell"><span>達成率</span></div>
                <div class="app-achv__cell"><span><?= e($shareText) ?></span></div>
            </div>

            <?php foreach ($rows as $row): ?>
                <?php
                $tone  = $toneOf($row['rate']);
                $width = $row['rate'] === null ? 0 : max(0, min(100, $row['rate']));
                ?>
                <div class="app-achv__item">
                    <div class="app-achv__row">
                        <div class="app-achv__name">
                            <i class="app-achv__dot" style="background: <?= e($row['color']) ?>"></i>
                            <span><?= e($row['label']) ?></span>
                            <?php if ($row['hint'] !== ''): ?>
                                <em class="app-achv__hint"><?= e($row['hint']) ?></em>
                            <?php endif; ?>
                        </div>

                        <div class="app-achv__cell">
                            <span class="app-achv__cell-label">預計</span>
                            <b><?= e($fmtQty($row['plan'])) ?></b>
                        </div>
                        <div class="app-achv__cell">
                            <span class="app-achv__cell-label">實際</span>
                            <b><?= e($fmtQty($row['actual'])) ?></b>
                        </div>
                        <div class="app-achv__cell">
                            <span class="app-achv__cell-label">差異</span>
                            <b class="app-achv__diff app-achv__diff--<?= ($row['diff'] ?? 0) < 0 ? 'down' : (($row['diff'] ?? 0) > 0 ? 'up' : 'flat') ?>">
                                <?= ($row['diff'] ?? 0) > 0 ? '+' : '' ?><?= e($fmtQty($row['diff'])) ?>
                            </b>
                        </div>
                        <div class="app-achv__cell">
                            <span class="app-achv__cell-label">達成率</span>
                            <b class="app-achv__rate app-achv__rate--<?= e($tone) ?>"><?= e($fmtRate($row['rate'])) ?></b>
                        </div>
                        <div class="app-achv__cell">
                            <span class="app-achv__cell-label"><?= e($shareText) ?></span>
                            <b class="app-achv__share"><?= e($fmtRate($row['share'])) ?></b>
                        </div>
                    </div>

                    <div class="app-achv__bar">
                        <span class="app-achv__bar-fill app-achv__bar-fill--<?= e($tone) ?>"
                              style="width: <?= e((string) round($width, 2)) ?>%"></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php
$listHtml = ob_get_clean();
?>
<div class="app-achv<?= $variant === 'plain' ? ' app-achv--plain' : '' ?>"
     id="<?= e($id) ?>"
     <?php if ($api !== ''): ?>data-achv-config='<?= e(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'<?php endif; ?>>

    <?php if ($title !== '' || $subtitle !== ''): ?>
        <div class="app-achv__head">
            <?php if ($icon !== ''): ?><i class="bi bi-<?= e($icon) ?>"></i><?php endif; ?>
            <span class="app-achv__title" data-role="achv-title"><?= e($title) ?></span>
            <span class="app-achv__subtitle" data-role="achv-subtitle"><?= e($subtitle) ?></span>
        </div>
    <?php endif; ?>

    <div class="app-achv__body" data-role="achv-body">
        <?= $summaryPos === 'top' ? $totalHtml . $listHtml : $listHtml . $totalHtml ?>
    </div>

    <div class="app-achv__foot<?= $footer === '' ? ' d-none' : '' ?>" data-role="achv-foot"><?= e($footer) ?></div>
</div>
