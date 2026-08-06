<?php
/**
 * 連線診斷頁。
 *
 * 接上公司實際的 db.php 時用這一頁：
 * 它會列出 db.php 到底產生了哪些變數、各是什麼型別，
 * 你就知道 config/database.php 的 legacy.map 要填哪個變數名。
 *
 * 只有 ADMIN 權限看得到。上線前可以直接把整個 dev/ 目錄刪掉。
 */

require __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Db\Db;
use App\Core\Db\LegacyBridge;

Auth::requirePermission('admin.view');

$inspect = LegacyBridge::inspect();

/** 測試某條連線能不能用 */
function probe(string $name): array
{
    try {
        $conn = Db::conn($name);
        $sql  = $conn->driver() === 'oracle'
            ? 'SELECT 1 AS ok FROM dual'
            : 'SELECT 1 AS ok';

        $conn->scalar($sql);

        return ['ok' => true, 'driver' => $conn->driver(), 'class' => get_class($conn)];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

$results = [];
foreach (array_keys(Config::get('database.legacy.map', [])) as $name) {
    $results[$name] = probe($name);
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <title>連線診斷</title>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <style>
        body { padding: 32px; }
        .wrap { max-width: 880px; margin: 0 auto; }
        table { background: #fff; }
        code { color: #b91c1c; }
    </style>
</head>
<body>
<div class="wrap">
    <h1 class="h4 mb-1">連線診斷</h1>
    <p class="text-muted">用來確認新架構有沒有正確接上既有的 <code>db.php</code>。</p>

    <h2 class="h6 mt-4">一、連線測試</h2>
    <table class="table table-sm table-bordered">
        <thead>
            <tr><th style="width:120px">連線名稱</th><th style="width:90px">結果</th><th>說明</th></tr>
        </thead>
        <tbody>
        <?php foreach ($results as $name => $r): ?>
            <tr>
                <td><code><?= e($name) ?></code></td>
                <td>
                    <?php if ($r['ok']): ?>
                        <span class="app-badge app-badge--run">正常</span>
                    <?php else: ?>
                        <span class="app-badge app-badge--alarm">失敗</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($r['ok']): ?>
                        方言 <code><?= e($r['driver']) ?></code>，
                        實作 <code><?= e($r['class']) ?></code>
                    <?php else: ?>
                        <?= e($r['error']) ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2 class="h6 mt-4">二、db.php 產生的變數</h2>
    <p class="text-muted small">
        若上面的自動辨識失敗，把下表中正確的變數名填進
        <code>config/database.php</code> 的 <code>legacy.map[連線名稱]['var']</code>。
    </p>
    <table class="table table-sm table-bordered">
        <thead><tr><th style="width:220px">變數</th><th>型別</th></tr></thead>
        <tbody>
        <?php foreach ($inspect as $var => $type): ?>
            <tr>
                <td><code><?= e($var) ?></code></td>
                <td><?= e($type) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($inspect === []): ?>
            <tr><td colspan="2" class="text-muted">db.php 沒有產生任何變數。</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <h2 class="h6 mt-4">三、環境</h2>
    <table class="table table-sm table-bordered">
        <tr><th style="width:220px">PHP 版本</th><td><?= e(PHP_VERSION) ?></td></tr>
        <tr><th>pdo_pgsql</th><td><?= extension_loaded('pdo_pgsql') ? '已載入' : '未載入' ?></td></tr>
        <tr><th>pgsql</th><td><?= extension_loaded('pgsql') ? '已載入' : '未載入' ?></td></tr>
        <tr><th>oci8</th><td><?= extension_loaded('oci8') ? '已載入' : '未載入' ?></td></tr>
        <tr><th>pdo_oci</th><td><?= extension_loaded('pdo_oci') ? '已載入' : '未載入' ?></td></tr>
        <tr><th>mbstring</th><td><?= extension_loaded('mbstring') ? '已載入' : '未載入' ?></td></tr>
        <tr><th>vendor/autoload.php</th><td><?= is_file(BASE_PATH . '/vendor/autoload.php') ? '存在' : '不存在（使用後備載入器）' ?></td></tr>
        <tr><th>storage/logs 可寫</th><td><?= is_writable(STORAGE_PATH . '/logs') ? '是' : '否（請開啟寫入權限）' ?></td></tr>
    </table>

    <a class="btn btn-secondary btn-sm" href="<?= e(url('/')) ?>">回首頁</a>
</div>
</body>
</html>
