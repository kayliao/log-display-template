<?php
/**
 * 指北針。
 *
 *   View::component('compass', [
 *       'angle'    => 23.5,            // 北方相對畫面正上方轉幾度（順時針為正）
 *       'label'    => '廠區座標北',    // 針底下那行字
 *       'size'     => 88,              // 直徑（px）
 *       'position' => 'bar',           // bar = 橫式（放工具列）
 *                                      // top-right / top-left / bottom-right / bottom-left = 疊在角落
 *                                      // 不給 = 一般內嵌元素
 *       'format'   => 'signed',        // signed  = 23.5° E（偏東 23.5 度）
 *                                      // bearing = 337.5°（0~360 方位角）
 *       'showAngle'=> true,            // 是否顯示角度數字
 *   ]);
 *
 * 角度吃 0~360 的任意值，也接受負數與小數：
 *   23.5   北方偏右 23.5 度
 *   -15    北方偏左 15 度（等同 345）
 *   137.4  北方指向右下
 * 內部一律收斂成 0~360，所以填 -15 跟填 345 是同一個結果。
 *
 * 為什麼用 SVG 畫而不是疊一張圖：
 *   - 角度是設定值，改角度只要改 config 的一個數字，不用重畫圖再換檔
 *   - 任何縮放都不會糊掉，現場的大螢幕與筆電看起來一樣清楚
 *   - 顏色吃 CSS 變數，跟全站配色一致，不會有一張淺色圖配深色底的狀況
 *   - 少一個要跟著版控與離線佈署的二進位檔
 *
 * 旋轉是 CSS transform，只轉羅盤本身；底下的角度數字不跟著轉，
 * 不然斜著看不懂。
 */

$rawAngle  = (float) ($angle ?? 0);
$size      = (int) ($size ?? 88);
$label     = $label ?? '';
$position  = $position ?? '';
$showAngle = $showAngle ?? true;
$format    = $format ?? 'signed';   // signed = 偏東/偏西；bearing = 0~360 方位角

/**
 * 轉圈本來就吃任意角度：填 23.5、137、-15、312.7 都可以，小數也行。
 * 先收斂到 0~360，同一個方向不會因為填法不同而算成兩個值
 * （填 -15 跟填 345 是同一件事）。
 */
$bearing = fmod($rawAngle, 360);
if ($bearing < 0) {
    $bearing += 360;
}

// 偏東/偏西的講法用 -180~180 比較直覺（「偏右 15 度」而不是「345 度」）
$signed = $bearing > 180 ? $bearing - 360 : $bearing;

$classes = ['app-compass'];

if ($position === 'bar') {
    // 放在工具列裡：橫式排版，不會壓到平面圖
    $classes[] = 'app-compass--inline';
} elseif ($position !== '' && $position !== 'none') {
    $classes[] = 'app-compass--pinned';
    $classes[] = 'app-compass--' . preg_replace('/[^a-z\-]/', '', strtolower($position));
}

/** 去掉沒有意義的小數點（23.50 => 23.5，90.0 => 90） */
$trim = function (float $value) {
    return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
};

if ($format === 'bearing') {
    $angleText = $trim($bearing) . '°';
} elseif ($signed == 0) {
    $angleText = '正北';
} else {
    // 正數標 E（偏東）、負數標 W（偏西），跟現場的講法一致
    $angleText = $trim(abs($signed)) . '° ' . ($signed > 0 ? 'E' : 'W');
}

// 旋轉一律用 0~360 的值，CSS 不需要知道我們怎麼稱呼它
$angle = $bearing;
?>
<div class="<?= e(implode(' ', $classes)) ?>"
     style="--compass-size: <?= $size ?>px; --compass-angle: <?= e((string) $angle) ?>deg"
     title="北方相對畫面正上方偏 <?= e((string) $angle) ?> 度">

    <svg class="app-compass__dial" viewBox="0 0 100 100" role="img"
         aria-label="指北針，北方偏 <?= e((string) $angle) ?> 度">

        <circle class="app-compass__face" cx="50" cy="50" r="46"/>

        <!-- 整個羅盤（刻度、方位字、指針）一起轉，轉的角度就是北方偏角 -->
        <g class="app-compass__rose">
            <?php
            /**
             * 刻度：每 15 度一格，四個正方位畫長一點。
             * 在 PHP 產生而不是寫死二十四行 <line>，改間隔只要改一個數字。
             */
            for ($deg = 0; $deg < 360; $deg += 15):
                $major = ($deg % 90) === 0;
                $rad   = deg2rad($deg - 90);          // -90：讓 0 度朝上
                $r1    = $major ? 37.5 : 41;
                ?>
                <line class="app-compass__tick<?= $major ? ' app-compass__tick--major' : '' ?>"
                      x1="<?= round(50 + $r1 * cos($rad), 2) ?>"
                      y1="<?= round(50 + $r1 * sin($rad), 2) ?>"
                      x2="<?= round(50 + 45.5 * cos($rad), 2) ?>"
                      y2="<?= round(50 + 45.5 * sin($rad), 2) ?>"/>
            <?php endfor; ?>

            <text class="app-compass__dir app-compass__dir--n" x="50" y="24">N</text>
            <text class="app-compass__dir" x="50" y="82">S</text>
            <text class="app-compass__dir" x="79" y="54">E</text>
            <text class="app-compass__dir" x="21" y="54">W</text>

            <!-- 指針：北半邊實心紅、南半邊淺灰，一眼看得出哪一頭是北 -->
            <polygon class="app-compass__needle-n" points="50,28 44.5,52 55.5,52"/>
            <polygon class="app-compass__needle-s" points="50,72 44.5,52 55.5,52"/>
            <circle class="app-compass__pin" cx="50" cy="52" r="3.2"/>
        </g>
    </svg>

    <?php if ($label !== '' || $showAngle): ?>
        <div class="app-compass__foot">
            <?php if ($label !== ''): ?>
                <span class="app-compass__label"><?= e($label) ?></span>
            <?php endif; ?>
            <?php if ($showAngle): ?>
                <span class="app-compass__angle"><?= e($angleText) ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
