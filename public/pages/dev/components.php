<?php
/**
 * 共用元件目錄。
 *
 * 給接手的人看的頁面：每個共用元件長什麼樣子、怎麼寫。
 * 上線前不想留著就把 public/pages/dev/ 與 config/menu.php 的 dev 那一段刪掉，
 * 其他功能不受影響。
 */

require __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\View;

Auth::requirePermission('dev.components');

View::render('pages/dev/components', [
    'title' => '共用元件目錄',
]);
