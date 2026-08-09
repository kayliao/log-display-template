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
// 全域共用的 UI 骨架：載入遮罩、共用彈窗、逾時提醒
View::partial('overlays');
?>

<script src="<?= e(asset('vendor/jquery/jquery.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/datatables/datatables.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/flatpickr/flatpickr.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/flatpickr/l10n/zh-tw.js')) ?>"></script>

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
<script src="<?= e(asset('js/app.machinemap.js')) ?>"></script>
<script src="<?= e(asset('js/app.session.js')) ?>"></script>

<?php foreach ($pageScripts ?? [] as $script): ?>
    <script src="<?= e(asset('js/' . $script)) ?>"></script>
<?php endforeach; ?>

<?= $inlineScript ?? '' ?>
</body>
</html>
