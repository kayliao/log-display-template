<?php
/**
 * 全站頁尾。
 *
 * 目前不顯示（版型預設 $showFooter = false），先把檔案留著。
 * 之後要開啟時，在頁面資料加上 'showFooter' => true 即可。
 */
?>
<footer class="app-footer">
    <div class="app-footer__inner">
        <span><?= e(config('app.name')) ?></span>
        <span class="app-footer__sep">·</span>
        <span>版本 <?= e(config('app.version')) ?></span>
        <span class="app-footer__sep">·</span>
        <span>系統問題請聯絡資訊課分機 1234</span>
    </div>
</footer>
