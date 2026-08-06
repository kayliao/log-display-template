<?php
/**
 * 登出。
 *
 * 清掉 Session 後回登入頁。
 * header 的倒數計時器歸零時，前端也是導到這一支。
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Response;

$wasTimeout = isset($_GET['timeout']);

Auth::logout();

Response::redirect($wasTimeout ? '/login.php?expired=1' : '/login.php');
