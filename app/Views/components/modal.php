<?php
/**
 * 通用彈窗外殼。
 *
 * 只負責「一個有標題、有內容、有關閉鈕的框」，內容塞什麼都行。
 * 表格上放大鏡跳出來的那個是 JS 動態產生的（App.modal），
 * 內容固定不變的彈窗（主選單、說明、確認）就用這一支寫死在樣板裡。
 *
 *   View::component('modal', [
 *       'id'      => 'appMenuModal',
 *       'title'   => '主選單',
 *       'icon'    => 'grid-3x3-gap-fill',
 *       'size'    => 'xl',
 *       'content' => View::componentHtml('menu_grid', ['groups' => Menu::cards()]),
 *   ]);
 *
 * 參數：
 *   id          彈窗 id，modal_button 的 target 要對到這個，必填
 *   title       標題文字
 *   icon        標題前的圖示
 *   size        sm | lg | xl | fullscreen；不給就是預設寬度
 *   centered    true = 垂直置中
 *   scrollable  true = 內容過長時只捲內容、標題與頁尾固定住
 *   content     內容 HTML（記得自己逸出）
 *   footer      頁尾 HTML；不給 = 一顆「關閉」；傳 false = 完全不要頁尾
 *   static      true = 點背景不會關掉（需要使用者明確做選擇時用）
 *   class       額外加在 .modal-dialog 上的 class
 *
 * 放的位置：
 *   頁面內容裡直接放沒問題；要給 header 上的按鈕用，
 *   請放在 partials/overlays.php（原因見 modal_button 的說明）。
 */

$id     = $id ?? 'appModal';
$size   = $size ?? '';
// 沒傳 footer = 用預設的關閉鈕；傳 false = 不要頁尾。isset 對 false 會回 true，剛好分得開。
$footer = isset($footer) ? $footer : null;

$dialogCls = 'modal-dialog';

if ($size !== '') {
    $dialogCls .= ' modal-' . $size;
}

if (!empty($centered)) {
    $dialogCls .= ' modal-dialog-centered';
}

if (!empty($scrollable)) {
    $dialogCls .= ' modal-dialog-scrollable';
}

if (!empty($class)) {
    $dialogCls .= ' ' . $class;
}
?>
<div class="modal fade" id="<?= e($id) ?>" tabindex="-1" aria-hidden="true"
     <?php if (!empty($static)): ?>data-bs-backdrop="static" data-bs-keyboard="false"<?php endif; ?>>
    <div class="<?= e($dialogCls) ?>">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title">
                    <?php if (!empty($icon)): ?>
                        <i class="bi bi-<?= e($icon) ?>"></i>
                    <?php endif; ?>
                    <span><?= e($title ?? '') ?></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
            </div>

            <div class="modal-body">
                <?= $content ?? '' ?>
            </div>

            <?php if ($footer !== false): ?>
                <div class="modal-footer">
                    <?php if ($footer === null): ?>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">關閉</button>
                    <?php else: ?>
                        <?= $footer ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>
