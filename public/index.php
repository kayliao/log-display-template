<?php
/**
 * 首頁。
 *
 * 內容完全依權限產生：
 *   - 公告提醒列
 *   - 功能小卡（來源是 config/menu.php，使用者沒權限的功能不會出現）
 *
 * 這一支是「頁面型入口」的標準長相：
 *   bootstrap -> 驗證 -> 呼叫 Service 取資料 -> View::render
 * 全檔不出現任何一句 SQL 與任何 HTML。
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Menu;
use App\Core\Response;
use App\Core\View;
use App\Domain\Announcement\AnnouncementService;

if (!Auth::check()) {
    Response::redirect('/login.php');
}

$announcements = (new AnnouncementService())->active(Auth::role());

View::render('pages/home', [
    'title'         => '首頁',
    'note'          => '依您的權限顯示可使用的功能。若有功能未顯示，請洽單位主管申請權限。',
    'announcements' => $announcements,
    'cards'         => Menu::cards(),
    'bodyClass'     => 'page-home',
]);
