<?php
/**
 * 一排按鈕。
 *
 *   View::component('button_group', [
 *       'align'   => 'right',                 // left | center | right | between
 *       'buttons' => [
 *           ['label' => '取消', 'variant' => 'secondary', 'attrs' => ['data-bs-dismiss' => 'modal']],
 *           ['label' => '儲存', 'variant' => 'primary', 'icon' => 'check-lg', 'type' => 'submit'],
 *       ],
 *   ]);
 *
 * 每個項目就是 button 元件的參數，所以按鈕本身的寫法只有一套。
 * 需要塞非按鈕的東西（例如一段說明文字）就用 'html' => '...'。
 */

use App\Core\View;

$align   = $align   ?? 'left';
$buttons = $buttons ?? [];
$gap     = $gap     ?? '';
?>
<div class="app-btngroup app-btngroup--<?= e($align) ?><?= $gap === 'tight' ? ' app-btngroup--tight' : '' ?>">
    <?php foreach ($buttons as $button): ?>
        <?php if (isset($button['html'])): ?>
            <?= $button['html'] ?>
        <?php else: ?>
            <?php View::component('button', $button); ?>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
