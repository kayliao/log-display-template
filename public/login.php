<?php
/**
 * 登入頁。
 *
 * ⚠ 這是暫時的示範版本。
 *
 * 依照規劃，登入邏輯會沿用公司原本的 PHP：
 * 拿到舊的登入程式後，把「驗證帳號密碼」那一段接進來，
 * 驗證成功後只要呼叫：
 *
 *     Auth::login([
 *         'emp_no' => $工號,
 *         'name'   => $姓名,
 *         'role'   => $角色代碼,
 *         'dept'   => $部門,
 *     ]);
 *
 * 其餘（Session、權限、倒數登出）由框架接手，不需要自己處理。
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

// 已登入就直接進首頁
if (Auth::check()) {
    Response::redirect('/');
}

$error   = '';
$expired = isset($_GET['expired']);

if (Request::isPost()) {
    $empNo    = Request::str('emp_no');
    $password = Request::str('password');

    // 防止暴力嘗試：同一個 Session 連續失敗五次就鎖三分鐘
    $fails    = (int) Session::get('_login_fails', 0);
    $lockedTo = (int) Session::get('_login_locked_to', 0);

    if ($lockedTo > time()) {
        $error = '嘗試次數過多，請於 ' . ceil(($lockedTo - time()) / 60) . ' 分鐘後再試。';
    } elseif ($empNo === '' || $password === '') {
        $error = '請輸入工號與密碼。';
    } else {
        /**
         * ===== 這一段之後換成公司既有的驗證邏輯 =====
         *
         * 例如：
         *   $user = (new UserRepository())->verify($empNo, $password);
         *   if ($user) { Auth::login($user); Response::redirect('/'); }
         *
         * 目前為了讓模板跑得起來，先用設定檔裡的測試帳號。
         */
        $demoUsers = Config::get('app.demo_users', [
            'admin' => ['password' => 'admin', 'name' => '系統管理員', 'role' => 'ADMIN',    'dept' => '資訊課'],
            'e001'  => ['password' => 'e001',  'name' => '王工程師',   'role' => 'ENGINEER', 'dept' => '製造課'],
            'v001'  => ['password' => 'v001',  'name' => '陳課長',     'role' => 'VIEWER',   'dept' => '品保課'],
        ]);

        $key = strtolower($empNo);

        if (isset($demoUsers[$key]) && hash_equals($demoUsers[$key]['password'], $password)) {
            Session::forget('_login_fails');
            Session::forget('_login_locked_to');

            Auth::login([
                'emp_no' => $empNo,
                'name'   => $demoUsers[$key]['name'],
                'role'   => $demoUsers[$key]['role'],
                'dept'   => $demoUsers[$key]['dept'],
            ]);

            Response::redirect('/');
        }

        $fails++;
        Session::set('_login_fails', $fails);

        if ($fails >= 5) {
            Session::set('_login_locked_to', time() + 180);
        }

        Logger::warning('登入失敗', ['emp_no' => $empNo, 'fails' => $fails]);
        $error = '工號或密碼錯誤。';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>登入 - <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="page-login">

<div class="login">
    <form class="login__box" method="post" autocomplete="off">
        <div class="login__brand">
            <i class="bi bi-hdd-network"></i>
        </div>
        <h1 class="login__title"><?= e(config('app.name')) ?></h1>
        <p class="login__sub">請使用您的工號登入</p>

        <?php if ($expired): ?>
            <div class="login__alert login__alert--warn">
                <i class="bi bi-clock-history"></i> 閒置過久已自動登出，請重新登入。
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="login__alert login__alert--error">
                <i class="bi bi-exclamation-circle"></i> <?= e($error) ?>
            </div>
        <?php endif; ?>

        <div class="login__field">
            <i class="bi bi-person"></i>
            <input type="text" name="emp_no" class="form-control" placeholder="工號"
                   value="<?= e(old('emp_no')) ?>" required autofocus>
        </div>

        <div class="login__field">
            <i class="bi bi-lock"></i>
            <input type="password" name="password" class="form-control" placeholder="密碼" required>
        </div>

        <button type="submit" class="btn btn-primary w-100 login__submit">登入</button>

        <p class="login__foot">版本 <?= e(config('app.version')) ?></p>
    </form>
</div>

</body>
</html>
