<?php
/**
 * 開彈窗的按鈕 —— 按一下，跳出指定的彈窗。
 *
 * 跟 dropdown 是一對：一個往下展開，一個跳出來蓋在畫面上。
 * 兩個長得一樣（都是 _trigger 畫的），所以擺在一起不會不搭。
 *
 *   View::component('modal_button', [
 *       'target' => 'appMenuModal',        // 要打開哪一個彈窗的 id
 *       'icon'   => 'grid-3x3-gap',
 *       'label'  => '主選單',
 *   ]);
 *
 * 參數：
 *   target   彈窗的 id（不含 #），必填
 *   icon     按鈕圖示
 *   label    按鈕文字
 *   variant  nav（預設）| icon
 *   caret    是否顯示小箭頭，預設 false
 *   active   true = 標示成「目前所在」
 *   title    滑鼠移上去的提示文字
 *
 * ⚠ 按鈕與彈窗本體可以分開放，而且在 header 裡「必須」分開：
 *   header 是 sticky + z-index，彈窗本體放進去會被遮罩蓋住。
 *   header 只放這顆按鈕，彈窗本體請放在 partials/overlays.php。
 */

use App\Core\View;
?>
<?= View::componentHtml('_trigger', [
    'variant' => $variant ?? 'nav',
    'icon'    => $icon ?? null,
    'label'   => $label ?? '',
    'title'   => $title ?? null,
    'caret'   => $caret ?? false,
    'active'  => $active ?? false,
    'id'      => $id ?? null,
    'attrs'   => [
        'data-bs-toggle' => 'modal',
        'data-bs-target' => '#' . ($target ?? ''),
    ],
]) ?>
