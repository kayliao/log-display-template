<?php
/**
 * 根目錄轉發。
 *
 * 這個系統支援兩種佈署方式：
 *
 *   A（建議）DocumentRoot 指到 public/
 *            => 這個檔案不會被用到，app/ config/ vendor/ 完全無法從瀏覽器存取。
 *
 *   B        DocumentRoot 指到專案根目錄
 *            => 由這個檔案把請求轉進 public/，
 *               並靠 .htaccess / web.config 擋掉非公開目錄。
 *
 * 之後 vhost 確定了，可以直接把用不到的那一套刪掉。
 */

$target = __DIR__ . '/public/index.php';

if (!is_file($target)) {
    http_response_code(500);
    exit('找不到 public/index.php，請確認專案檔案是否完整。');
}

require $target;
