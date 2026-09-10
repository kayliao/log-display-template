<?php
/**
 * 舊頁面用的 header 轉接檔。
 *
 * 讓尚未改版的舊頁面也能長出統一的 header（選單、程式說明、倒數登出），
 * 而且只需要在舊檔最上面加一行：
 *
 *     <?php require __DIR__ . '/_legacy_header.php'; ?>
 *
 * 舊頁面自己的 HTML 與樣式完全不用改。
 *
 * 注意：舊頁面通常有自己的 <html><head>，
 * 所以這裡只輸出必要的 CSS 與 header 區塊，不輸出完整的 HTML 骨架。
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Menu;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

if (!Auth::check()) {
    Response::redirect('/login.php');
}

$title = Menu::pageTitle(null);
$note  = Menu::pageNote(null);
?>
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">

<script>
window.APP_CONFIG = <?= json_encode([
    'baseUrl'         => url('/'),
    'sessionSeconds'  => Session::secondsLeft(),
    'warnBefore'      => (int) config('app.session.warn_before', 180),
    'renewOnActivity' => (bool) config('app.session.renew_on_activity', true),
    'user'            => user(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
</script>

<?php
View::partial('header', ['title' => $title, 'note' => $note]);
View::partial('overlays');

/**
 * ⚠ 這裡是**刻意**手寫一份最小清單，不是走 partials/scripts.php。
 *
 *   舊頁面只需要 header 會動（下拉、逾時提醒）就好，不需要表格、甘特圖那些；
 *   而且 scripts.php 會連 jQuery 一起吐出去 —— 舊頁通常自己已經有一份，
 *   後載入的 jQuery 會把前一個連同上面所有外掛（selectize 那類）整個蓋掉。
 *
 * ★ 所以**這一頁不可以同時走版型**（`View::render()`）。
 *
 *   兩邊都跑的話 Bootstrap 會被載入兩次，症狀是「下拉點了完全沒反應而且不報錯」
 *   （它的下拉是委派到 document 的 click，載兩次就註冊兩次，開了又立刻關）。
 *   舊頁改寫成新頁的時候，記得把這一行 require 刪掉。
 *
 *   查法：Console 打
 *   document.querySelectorAll('script[src*="bootstrap"]').length —— 不是 1 就有問題。
 *
 * 舊頁想用模板的元件（表格、彈窗、日期區間…）就別用這一支，改成：
 *
 *     View::partial('scripts', ['vendor' => false]);
 *
 *   那樣只出 app.*.js，vendor 全部沿用舊頁自己那一份。
 */
?>

<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.core.js')) ?>"></script>
<script src="<?= e(asset('js/app.loading.js')) ?>"></script>
<script src="<?= e(asset('js/app.http.js')) ?>"></script>
<script src="<?= e(asset('js/app.session.js')) ?>"></script>
