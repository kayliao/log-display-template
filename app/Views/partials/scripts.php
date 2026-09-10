<?php
/**
 * 全站共用的 JS（含載入遮罩、共用彈窗那些骨架）。
 *
 * ★★ 這一份**不管被 include 幾次，只會輸出一次**。
 *
 *   舊系統搬遷期間，一定會有一段時間是「新舊兩套並存」：
 *   新頁面走 View::render() 套版型，舊頁面自己吐 HTML、自己 include script。
 *   只要有一頁同時走了兩條路，Bootstrap 就會被載入兩次。
 *
 *   而 Bootstrap 重複載入的症狀非常難認：
 *
 *     它的下拉是用「委派到 document 的 click」實作的，載兩次就註冊兩次。
 *     點一下 → toggle 兩次 → 開了又立刻關 → **看起來完全沒反應**。
 *     沒有錯誤訊息，Console 乾乾淨淨，而且用程式呼叫
 *     bootstrap.Dropdown.toggle() 是好的（那只跑一次）。
 *     彈窗、頁籤也會有類似的怪毛病。
 *
 *   我們自己的 app.*.js 每一支開頭都有 App.__loaded 守衛，重複載入不會出事；
 *   **第三方套件沒有這種守衛**，所以要在這一層擋。
 *
 * ─── 舊頁面要怎麼用 ───────────────────────────────────
 *
 * ★★ 舊頁面**不要**原封不動 include 這一支。
 *
 *   舊頁面通常自己已經有一整套 vendor，而且往往還多了我們沒有的
 *   jQuery 外掛（selectize 這類）。再給它一份 jQuery 的後果是：
 *
 *       舊頁的 jquery.js    -> window.$ = 實例A
 *       舊頁的 selectize.js -> 實例A.fn.selectize = ...
 *       我們的 jquery.js    -> window.$ = 實例B（全新，沒有任何外掛）
 *       舊頁呼叫 $().selectize -> 找實例B -> Uncaught TypeError
 *
 *   **後載入的 jQuery 會把前一個連同上面所有外掛整個蓋掉。**
 *
 * 舊頁面如果想用我們的元件（表格、彈窗、日期區間…），傳 vendor => false：
 *
 *     <?php \App\Core\View::partial('scripts', ['vendor' => false]); ?>
 *
 *   那樣只會輸出 app.*.js，jQuery / Bootstrap / DataTables / flatpickr
 *   全部沿用舊頁自己那一份。
 *
 *   ⚠ 前提是舊頁那些 vendor 的版本要對得上（Bootstrap 5、jQuery 3）。
 *     對不上的話 app.*.js 會有奇怪的行為，那種情況就別混用，
 *     等那一頁真的改版時再整頁搬進版型。
 */

// 已經輸出過就直接跳出。樣板是在獨立函式範圍執行的，return 只結束這一支。
if (defined('APP_SCRIPTS_RENDERED')) {
    return;
}

define('APP_SCRIPTS_RENDERED', true);

use App\Core\View;

// 舊頁面自己已經有 vendor 時傳 false，只出我們的 app.*.js
$vendor = ($vendor ?? true) !== false;

// 全域共用的 UI 骨架：載入遮罩、共用彈窗、逾時提醒
View::partial('overlays');
?>

<?php if ($vendor): ?>
<script src="<?= e(asset('vendor/jquery/jquery.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/datatables/datatables.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/flatpickr/flatpickr.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/flatpickr/l10n/zh-tw.js')) ?>"></script>
<?php endif; ?>

<script src="<?= e(asset('js/app.core.js')) ?>"></script>
<script src="<?= e(asset('js/app.loading.js')) ?>"></script>
<script src="<?= e(asset('js/app.http.js')) ?>"></script>
<script src="<?= e(asset('js/app.modal.js')) ?>"></script>
<script src="<?= e(asset('js/app.table.js')) ?>"></script>
<script src="<?= e(asset('js/app.tabs.js')) ?>"></script>
<script src="<?= e(asset('js/app.filter.js')) ?>"></script>
<script src="<?= e(asset('js/app.daterange.js')) ?>"></script>
<script src="<?= e(asset('js/app.multi.js')) ?>"></script>
<script src="<?= e(asset('js/app.upload.js')) ?>"></script>
<script src="<?= e(asset('js/app.achievement.js')) ?>"></script>
<script src="<?= e(asset('js/app.stat.js')) ?>"></script>
<script src="<?= e(asset('js/app.sum.js')) ?>"></script>
<script src="<?= e(asset('js/app.machinemap.js')) ?>"></script>
<script src="<?= e(asset('js/app.gantt.js')) ?>"></script>
<script src="<?= e(asset('js/app.session.js')) ?>"></script>

<?php
/**
 * 頁面專屬的 JS。
 *
 * ⚠ 這一段跟上面不一樣：它**每一頁的內容都不同**，所以不受上面那個
 *   「只輸出一次」的守衛保護——如果同一頁真的走了兩次版型，
 *   這裡還是會出兩份。不過那時候上面已經擋住了，也就不會發生。
 */
?>
<?php foreach ($pageScripts ?? [] as $script): ?>
    <script src="<?= e(asset('js/' . $script)) ?>"></script>
<?php endforeach; ?>

<?= $inlineScript ?? '' ?>
