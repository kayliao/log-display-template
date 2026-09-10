<?php
/**
 * 主版型。
 *
 * 所有登入後的頁面都套這個版型。
 * 靜態檔一律從本機 assets/vendor 載入——現場沒有網路，不能用 CDN。
 *
 * 可用變數：
 *   $title       頁面標題（沒給就用選單設定的名稱）
 *   $note        程式說明（沒給就用選單設定的說明）
 *   $content     頁面內容（由 View::render 產生）
 *   $bodyClass   額外的 body class
 *   $pageScripts 這一頁額外要載入的 JS 檔（相對於 assets/js）
 *   $pageStyles  這一頁額外要載入的 CSS 檔（相對於 assets/css）
 *   $showFooter  是否顯示頁尾，預設不顯示
 */

use App\Core\Menu;
use App\Core\Session;
use App\Core\View;

$title = Menu::pageTitle($title ?? null);
$note  = Menu::pageNote($note ?? null);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ? $title . ' - ' . config('app.name') : config('app.name')) ?></title>

    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/datatables/datatables.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/flatpickr/flatpickr.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <?php foreach ($pageStyles ?? [] as $style): ?>
        <link rel="stylesheet" href="<?= e(asset('css/' . $style)) ?>">
    <?php endforeach; ?>

    <?php
    /**
     * 前端執行期設定。
     * 所有 JS 需要知道的後端資訊都從這裡拿，不要在 JS 裡寫死路徑。
     */
    $bootData = [
        'baseUrl'        => url('/'),
        'csrfToken'      => Session::get('_csrf'),
        'sessionSeconds' => Session::secondsLeft(),
        'warnBefore'     => (int) config('app.session.warn_before', 180),
        'renewOnActivity'=> (bool) config('app.session.renew_on_activity', true),
        'queryRange'     => config('app.query_range', []),
        'user'           => user(),
    ];
    ?>
    <script>window.APP_CONFIG = <?= json_encode($bootData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;</script>
</head>
<body class="<?= e($bodyClass ?? '') ?>">

<?php View::partial('header', ['title' => $title, 'note' => $note]); ?>

<?php if (config('app.demo_mode')): ?>
    <!--
      示範模式提示列。
      故意做得明顯，讓人不可能忘記自己還在看假資料。
      關閉方式：config/app.php 的 demo_mode 改成 false。
    -->
    <div class="app-demobar">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <strong>示範模式</strong>
        <span>畫面上是內建的假資料，並未連線任何資料庫。接上真實資料庫後請把 <code>config/app.php</code> 的 <code>demo_mode</code> 改成 <code>false</code>。</span>
    </div>
<?php endif; ?>

<main class="app-main">
    <?= $content ?>
</main>

<?php if (!empty($showFooter)): ?>
    <?php View::partial('footer'); ?>
<?php endif; ?>

<?php
/**
 * 共用 JS 與 UI 骨架。
 *
 * ★ 拆成獨立的 partial 而不是寫在這裡，是為了**新舊系統並存的那段期間**。
 *
 *   舊頁面不走這個版型、自己吐 HTML，但它一樣需要 Bootstrap 那些東西。
 *   如果各自 include 各自的一份，總有一頁會同時走兩條路而載入兩次——
 *   Bootstrap 重複載入的症狀是「下拉點了完全沒反應、而且不報錯」，
 *   非常難查（詳細說明見 partials/scripts.php 的檔頭）。
 *
 *   那一支自己有「只輸出一次」的守衛，所以兩邊都 include 也安全，
 *   而且 JS 清單只有一份，加減檔案只要改一個地方。
 */
View::partial('scripts');
?>

</body>
</html>
