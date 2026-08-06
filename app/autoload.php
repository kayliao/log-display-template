<?php
/**
 * 後備自動載入器。
 *
 * 正常情況下會使用 vendor/autoload.php（Composer 產生）。
 * 但工廠現場沒有網路、也可能沒裝 Composer，萬一 vendor/ 遺失或損毀時，
 * 這支簡易 PSR-4 載入器可以讓系統照常啟動，不會整站掛掉。
 *
 * 對應規則：App\Core\Db\PdoConnection => app/Core/Db/PdoConnection.php
 */

spl_autoload_register(function ($class) {
    $prefix  = 'App\\';
    $baseDir = __DIR__ . '/';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = $baseDir . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
