/**
 * 載入中遮罩。
 *
 * 兩種用法：
 *   App.loading.show('查詢中…')      整頁遮罩
 *   App.loading.block(el, true)      只遮住某一塊（例如單張表格）
 *
 * 用計數器管理，同時有多支 API 在跑時不會被先回來的那支提早關掉。
 */
window.App = window.App || {};

(function (App) {
    'use strict';

    // 同一支檔案被載入兩次時，第二次直接跳出（原因見 app.core.js 開頭）
    App.__loaded = App.__loaded || {};
    if (App.__loaded.loading) return;
    App.__loaded.loading = true;

    var counter = 0;
    var el = null;
    var textEl = null;

    function ensure() {
        if (!el) {
            el = document.getElementById('appLoading');
            textEl = el ? el.querySelector('[data-role="loading-text"]') : null;
        }
        return el;
    }

    App.loading = {
        show: function (message) {
            var box = ensure();
            if (!box) return;

            counter++;

            if (textEl) {
                textEl.textContent = message || '資料查詢中，請稍候…';
            }
            box.hidden = false;
        },

        hide: function () {
            var box = ensure();
            if (!box) return;

            counter = Math.max(0, counter - 1);

            if (counter === 0) {
                box.hidden = true;
            }
        },

        /** 強制關閉（例外情況用，例如頁面切換） */
        reset: function () {
            counter = 0;
            var box = ensure();
            if (box) box.hidden = true;
        },

        /**
         * 區塊型遮罩：只蓋住指定容器。
         * 表格重新查詢時用這個，使用者還能操作頁面其他部分。
         */
        block: function (container, on) {
            if (!container) return;

            var mask = container.querySelector(':scope > .app-loading--inline');

            if (on) {
                if (!mask) {
                    mask = document.createElement('div');
                    mask.className = 'app-loading app-loading--inline';
                    mask.innerHTML = '<div class="app-loading__box">' +
                                     '<div class="app-loading__spinner"></div>' +
                                     '</div>';
                    container.appendChild(mask);
                }
                mask.hidden = false;
            } else if (mask) {
                mask.hidden = true;
            }
        }
    };

})(window.App);
