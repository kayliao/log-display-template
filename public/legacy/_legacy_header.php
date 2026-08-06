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
?>

<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.core.js')) ?>"></script>
<script src="<?= e(asset('js/app.loading.js')) ?>"></script>
<script src="<?= e(asset('js/app.http.js')) ?>"></script>
<script src="<?= e(asset('js/app.session.js')) ?>"></script>
