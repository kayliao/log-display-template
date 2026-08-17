/**
 * 倒數登出。
 *
 * header 右側那個時鐘就是這支在跑。
 *
 * 重要觀念：真正決定登出的是後端（App\Core\Session 的閒置檢查），
 * 前端倒數只是「讓使用者看得到還剩多久」。
 * 所以這裡會定期跟後端校時，避免瀏覽器分頁被系統凍結後時間對不上。
 *
 * 行為：
 *   - 使用者有操作（點擊、打字、捲動）就延長，但最多每 60 秒送一次心跳
 *   - 剩下 warn_before 秒時跳出提醒視窗，可以按「繼續使用」延長
 *   - 歸零時導向 logout.php?timeout=1
 */
window.App = window.App || {};

(function (App) {
    'use strict';

    // 同一支檔案被載入兩次時，第二次直接跳出（原因見 app.core.js 開頭）
    App.__loaded = App.__loaded || {};
    if (App.__loaded.session) return;
    App.__loaded.session = true;

    /**
     * 這三個值在 init() 裡才讀（見檔案最後的 DOMContentLoaded）。
     *
     * 不在這裡直接讀 App.config：那是載入期，如果這支排在 app.core.js
     * 前面就會拿到 undefined，secondsLeft 變成 0 ——
     * 使用者一進頁面就被告知即將逾時，而且不會有任何錯誤訊息可查。
     * 等到 DOMContentLoaded 時 14 支一定都載完了，順序就不再有影響。
     */
    var cfg         = {};
    var secondsLeft = 0;
    var warnBefore  = 180;

    var lastTouch  = 0;
    var timer      = null;
    var warned     = false;
    var countdownEl, timeEl, modalEl, modalCountEl, modalInstance;

    /** 秒數轉成 mm:ss */
    function fmt(seconds) {
        var m = Math.floor(seconds / 60);
        var s = seconds % 60;
        return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    }

    function paint() {
        if (!timeEl) return;

        timeEl.textContent = fmt(Math.max(0, secondsLeft));

        countdownEl.classList.toggle('is-warning', secondsLeft <= warnBefore && secondsLeft > 60);
        countdownEl.classList.toggle('is-danger',  secondsLeft <= 60);

        if (modalCountEl) {
            modalCountEl.textContent = Math.max(0, secondsLeft);
        }
    }

    function tick() {
        secondsLeft--;

        if (secondsLeft <= warnBefore && !warned) {
            warned = true;
            if (modalInstance) modalInstance.show();
        }

        if (secondsLeft <= 0) {
            clearInterval(timer);
            window.location.href = App.url('/logout.php?timeout=1');
            return;
        }

        paint();
    }

    /**
     * 送心跳延長 Session。
     * 節流成最多 60 秒一次——使用者狂點滑鼠不該打爆伺服器。
     */
    function touch(force) {
        var now = Date.now();

        if (!force && (now - lastTouch < 60000)) return;
        lastTouch = now;

        App.http.get('/api/session/heartbeat.php', { renew: 1 }, { loading: false, quiet: true })
            .then(function (data) {
                secondsLeft = data.seconds_left;
                warnBefore  = data.warn_before;
                warned      = false;

                if (modalInstance) modalInstance.hide();

                paint();
            })
            .catch(function () {
                // 心跳失敗不打擾使用者，下一次操作會再試
            });
    }

    function init() {
        countdownEl = document.getElementById('sessionCountdown');
        if (!countdownEl) return;   // 登入頁沒有 header，直接跳過

        cfg         = App.config || {};
        secondsLeft = parseInt(cfg.sessionSeconds, 10) || 0;
        warnBefore  = parseInt(cfg.warnBefore, 10) || 180;

        timeEl  = countdownEl.querySelector('[data-role="countdown"]');
        modalEl = document.getElementById('appTimeoutModal');

        if (modalEl) {
            modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
            modalCountEl  = modalEl.querySelector('[data-role="timeout-count"]');

            modalEl.querySelector('[data-role="timeout-stay"]')
                .addEventListener('click', function () { touch(true); });
        }

        paint();
        timer = setInterval(tick, 1000);

        // 使用者操作就延長
        if (cfg.renewOnActivity !== false) {
            ['click', 'keydown', 'scroll'].forEach(function (evt) {
                document.addEventListener(evt, function () { touch(false); }, { passive: true });
            });
        }

        // 分頁重新可見時跟後端校時。
        // 瀏覽器會凍結背景分頁的計時器，不校時的話回來會顯示錯誤的剩餘時間。
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) touch(true);
        });
    }

    App.session = { touch: touch };

    document.addEventListener('DOMContentLoaded', init);

})(window.App);
