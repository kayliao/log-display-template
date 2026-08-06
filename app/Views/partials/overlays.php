<?php
/**
 * 全域共用的 UI 骨架。
 *
 * 這些東西每頁都需要，所以由版型統一輸出一次，
 * 頁面只要呼叫 App.loading.show() / App.modal.detail() 就能用。
 *
 *   1. 載入中遮罩
 *   2. 通用詳細資料彈窗（放大鏡點下去用的，可裝多段「標題 + 表格」）
 *   3. 程式說明彈窗
 *   4. 主選單彈窗（header 的「主選單」按鈕打開的）
 *   5. 閒置即將逾時提醒
 *   6. 右下角訊息提示
 *
 * 為什麼主選單彈窗放這裡、按鈕卻在 header？
 * header 是 sticky 而且有 z-index，等於自成一個堆疊層；
 * 彈窗的遮罩是 Bootstrap 掛在 body 上的，層級比 header 高，
 * 彈窗本體若留在 header 裡就會被自己的遮罩蓋住，變成點不到。
 */

use App\Core\Menu;
use App\Core\View;
?>

<!-- 1. 載入中遮罩 -->
<div class="app-loading" id="appLoading" hidden>
    <div class="app-loading__box">
        <div class="app-loading__spinner"></div>
        <div class="app-loading__text" data-role="loading-text">資料查詢中，請稍候…</div>
    </div>
</div>

<!-- 2. 通用詳細資料彈窗 -->
<div class="modal fade" id="appDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" data-role="detail-title">詳細資料</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
            </div>
            <div class="modal-body" data-role="detail-body">
                <!-- 內容由 App.modal.detail() 動態產生：可以是多組「小標題 + 表格」 -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">關閉</button>
            </div>
        </div>
    </div>
</div>

<!-- 3. 程式說明彈窗 -->
<div class="modal fade" id="appNoteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-info-circle"></i>
                    <span data-role="note-title">程式說明</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
            </div>
            <div class="modal-body app-note" data-role="note-body"></div>
        </div>
    </div>
</div>

<!-- 4. 主選單彈窗：把使用者有權限的功能全部攤開 -->
<?php View::component('modal', [
    'id'         => 'appMenuModal',
    'title'      => '主選單',
    'icon'       => 'grid-3x3-gap-fill',
    'size'       => 'xl',
    'scrollable' => true,
    'class'      => 'app-menumodal',
    'content'    => View::componentHtml('menu_grid', [
        'groups'  => Menu::cards(),
        'compact' => true,
    ]),
    'footer'     => false,
]); ?>

<!-- 5. 閒置即將逾時提醒 -->
<div class="modal fade" id="appTimeoutModal" tabindex="-1" data-bs-backdrop="static" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-body text-center py-4">
                <i class="bi bi-clock-history app-timeout__icon"></i>
                <p class="mb-1">您已閒置一段時間</p>
                <p class="app-timeout__count">
                    <span data-role="timeout-count">--</span> 秒後將自動登出
                </p>
                <button type="button" class="btn btn-primary w-100" data-role="timeout-stay">
                    繼續使用
                </button>
            </div>
        </div>
    </div>
</div>

<!-- 6. 訊息提示 -->
<div class="app-toasts" id="appToasts" aria-live="polite" aria-atomic="true"></div>
