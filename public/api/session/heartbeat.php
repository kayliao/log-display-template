<?php
/**
 * Session 心跳。
 *
 * 前端 App.session 有兩個用途會打這一支：
 *   1. 使用者有操作時延長 Session（renew=1）
 *   2. 定期校正倒數計時器（避免瀏覽器分頁被系統凍結導致時間不準）
 *
 * 回傳剩餘秒數，前端據此更新 header 的倒數顯示。
 */

require __DIR__ . '/../_boot.php';

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

if (Request::bool('renew') && Config::get('app.session.renew_on_activity', true)) {
    Session::touch();
}

Response::ok([
    'seconds_left' => Session::secondsLeft(),
    'warn_before'  => (int) Config::get('app.session.warn_before', 180),
]);
