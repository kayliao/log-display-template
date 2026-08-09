<?php
/**
 * 指北針。
 *
 *   View::component('compass', [
 *       'angle'    => 23.5,            // 北方相對畫面正上方偏幾度（順時針為正）
 *       'label'    => '廠區座標北',    // 針底下那行字
 *       'size'     => 88,              // 直徑（px）
 *       'position' => 'top-right',     // 疊在容器角落；不給就是一般內嵌元素
 *       'showAngle'=> true,            // 是否顯示「23.5°」數字
 *   ]);
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

$angle     = (float) ($angle ?? 0);
$size      = (int) ($size ?? 88);
$label     = $label ?? '';
$position  = $position ?? '';
$showAngle = $showAngle ?? true;

// -180 ~ 180，填 350 跟填 -10 是同一件事，顯示時要一致
$angle = fmod($angle, 360);
if ($angle > 180)  { $angle -= 360; }
if ($angle < -180) { $angle += 360; }

$classes = ['app-compass'];
if ($position !== '' && $position !== 'none') {
    $classes[] = 'app-compass--pinned';
    $classes[] = 'app-compass--' . preg_replace('/[^a-z\-]/', '', strtolower($position));
}

// 角度文字：正數標 E（偏東）、負數標 W（偏西），跟現場的講法一致
$angleText = $angle == 0
    ? '正北'
    : rtrim(rtrim(number_format(abs($angle), 1), '0'), '.') . '° ' . ($angle > 0 ? 'E' : 'W');
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
