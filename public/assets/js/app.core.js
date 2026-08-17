/**
 * App 核心：命名空間、共用工具、格式化。
 *
 * 所有前端程式碼都掛在 window.App 底下，不污染全域。
 * 後端傳來的設定在 window.APP_CONFIG（由版型輸出），
 * JS 裡不要寫死任何網址。
 *
 * 這個檔案必須第一個載入。
 */
window.App = window.App || {};

/**
 * 同一支 app.*.js 被載入兩次時的擋門。
 *
 * 舊系統常有好幾份 header，搬遷過程中同一頁很容易載到兩次 app.core.js。
 * 檔案本身會走瀏覽器快取，那不是問題；問題是 IIFE 會再跑一次，
 * DOMContentLoaded 的 listener 也會再註冊一次，於是每個元件被初始化兩次：
 * 公告輪播跑兩個 timer、上下鍵綁兩個 click（點一下跳兩則）。
 *
 * 每一支 app.*.js 開頭都寫 if (!App.once('名字')) return;
 * 第二次載入就安靜地跳出，不必先追出是哪一份 header 多加了 script 標籤。
 *
 * 定義在 IIFE 外面，這樣 core 自己被擋掉的那一次，once() 依然存在。
 */
window.App.once = window.App.once || function (name) {
    window.App.__loaded = window.App.__loaded || {};

    if (window.App.__loaded[name]) {
        return false;
    }

    window.App.__loaded[name] = true;

    return true;
};

(function (App) {
    'use strict';

    if (!App.once('core')) return;

    var cfg = window.APP_CONFIG || {};

    App.config = cfg;

    /**
     * 產生系統內網址。
     */
    App.url = function (path) {
        if (!path) return cfg.baseUrl || '/';
        if (/^https?:\/\//i.test(path)) return path;
        return (cfg.baseUrl || '/').replace(/\/$/, '') + '/' + String(path).replace(/^\//, '');
    };

    /**
     * HTML 逸出。凡是要塞進 innerHTML 的資料都必須經過這裡。
     */
    App.esc = function (value) {
        if (value === null || value === undefined) return '';
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    };

    /**
     * 依欄位設定的 format 把值轉成顯示文字。
     * 表格、彈窗、匯出預覽共用同一套，畫面上不會出現兩種格式。
     */
    App.format = {
        number: function (v) {
            if (v === null || v === undefined || v === '') return '';
            var n = Number(v);
            return isNaN(n) ? App.esc(v) : n.toLocaleString('zh-TW');
        },

        decimal: function (v, digits) {
            if (v === null || v === undefined || v === '') return '';
            var n = Number(v);
            return isNaN(n) ? App.esc(v) : n.toFixed(digits === undefined ? 2 : digits);
        },

        percent: function (v) {
            if (v === null || v === undefined || v === '') return '';
            var n = Number(v);
            return isNaN(n) ? App.esc(v) : n.toFixed(1) + '%';
        },

        datetime: function (v) {
            if (!v) return '';
            // 後端已經格式化成 YYYY-MM-DD HH:MM:SS，這裡只做斷行處理
            return App.esc(String(v).replace('T', ' ').substring(0, 19));
        },

        date: function (v) {
            if (!v) return '';
            return App.esc(String(v).substring(0, 10));
        },

        /** 機台狀態徽章 */
        status: function (v, row) {
            if (!v) return '';
            var code  = String(v).toLowerCase();
            var label = (row && row.status_label) ? row.status_label : v;
            return '<span class="app-badge app-badge--' + App.esc(code) + '">' + App.esc(label) + '</span>';
        },

        /**
         * 套用欄位設定。沒指定 format 就原樣輸出（已逸出）。
         */
        apply: function (col, value, row) {
            var fn = col && col.format ? App.format[col.format] : null;
            return fn ? fn(value, row) : App.esc(value);
        }
    };

    /**
     * 右下角訊息提示。
     * type: success | info | warning | error
     */
    App.toast = function (message, type, traceId) {
        var box = document.getElementById('appToasts');
        if (!box) { return; }

        var icons = {
            success: 'check-circle-fill',
            info: 'info-circle-fill',
            warning: 'exclamation-triangle-fill',
            error: 'x-circle-fill'
        };
        type = type || 'info';

        var el = document.createElement('div');
        el.className = 'app-toast app-toast--' + type;
        el.innerHTML =
            '<i class="bi bi-' + (icons[type] || icons.info) + ' app-toast__icon"></i>' +
            '<div class="app-toast__body">' + App.esc(message) +
            (traceId ? '<div class="app-toast__trace">代碼 ' + App.esc(traceId) + '</div>' : '') +
            '</div>' +
            '<button type="button" class="app-toast__close" aria-label="關閉"><i class="bi bi-x"></i></button>';

        el.querySelector('.app-toast__close').addEventListener('click', function () {
            el.remove();
        });

        box.appendChild(el);

        // 錯誤訊息留久一點，讓現場來得及抄下代碼
        setTimeout(function () { el.remove(); }, type === 'error' ? 12000 : 4000);
    };

    /**
     * 讀取元素上的 JSON 設定（data-xxx-config）。
     */
    App.readConfig = function (el, attr) {
        if (!el) return null;
        var raw = el.getAttribute(attr);
        if (!raw) return null;
        try {
            return JSON.parse(raw);
        } catch (e) {
            console.error('設定解析失敗：' + attr, e);
            return null;
        }
    };

    /**
     * 把表單欄位收集成物件（空值不送出，網址才不會一長串）。
     */
    App.serialize = function (form) {
        var data = {};
        if (!form) return data;

        Array.prototype.forEach.call(form.querySelectorAll('[name]'), function (el) {
            if (el.disabled) return;

            /**
             * 勾選類的欄位要看 checked，不能只看 value。
             * 只看 value 的話，沒有勾起來的核取方塊也會被送出去
             * （它的 value 一直都是 "1"），條件列上的勾選就變成永遠開著。
             */
            if (el.type === 'checkbox' || el.type === 'radio') {
                if (!el.checked) return;
            }

            var value = el.value;
            if (value === null || value === undefined || value === '') return;
            data[el.name] = value;
        });

        return data;
    };

    /** 防抖：避免使用者連打查詢按鈕 */
    App.debounce = function (fn, wait) {
        var timer;
        return function () {
            var args = arguments, ctx = this;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(ctx, args); }, wait || 300);
        };
    };

    App.date = {
        /** Date -> YYYY-MM-DD */
        toISO: function (d) {
            var m = String(d.getMonth() + 1).padStart(2, '0');
            var day = String(d.getDate()).padStart(2, '0');
            return d.getFullYear() + '-' + m + '-' + day;
        },

        /** 今天往前推 n 天 */
        daysAgo: function (n) {
            var d = new Date();
            d.setDate(d.getDate() - n);
            return d;
        }
    };

    /** 啟用頁面上所有的 tooltip（欄位說明泡泡） */
    App.initTooltips = function (scope) {
        var root = scope || document;
        if (!window.bootstrap || !bootstrap.Tooltip) return;

        Array.prototype.forEach.call(
            root.querySelectorAll('[data-bs-toggle="tooltip"]'),
            function (el) {
                if (!bootstrap.Tooltip.getInstance(el)) {
                    new bootstrap.Tooltip(el, { container: 'body' });
                }
            }
        );
    };

    document.addEventListener('DOMContentLoaded', function () {
        App.initTooltips();

        // header 的「程式說明」
        var noteBtn = document.getElementById('btnPageNote');
        if (noteBtn) {
            noteBtn.addEventListener('click', function () {
                var modalEl = document.getElementById('appNoteModal');
                var note    = noteBtn.getAttribute('data-note') || '這個頁面尚未提供說明。';
                var title   = noteBtn.getAttribute('data-title') || '程式說明';

                modalEl.querySelector('[data-role="note-title"]').textContent = title;
                modalEl.querySelector('[data-role="note-body"]').textContent  = note;

                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            });
        }

        // 公告輪播
        App.initAnnouncement();
    });

    /**
     * 公告列：多則時自動輪播，滑鼠移上去暫停。
     *
     * 呼叫幾次都安全：已經裝好的公告列會直接跳出，不會多綁一組
     * timer 與 click。AJAX 之後才插進來的公告列沒有這個記號，
     * 所以插完再呼叫一次就會正常初始化。
     */
    App.initAnnouncement = function () {
        var box = document.getElementById('appAnnounce');
        if (!box) return;

        var items = box.querySelectorAll('.app-announce__item');
        if (items.length < 2) return;

        if (box.getAttribute('data-announce-ready') === '1') return;
        box.setAttribute('data-announce-ready', '1');

        var index   = 0;
        var indexEl = box.querySelector('[data-role="announce-index"]');
        var timer   = null;

        function show(i) {
            index = (i + items.length) % items.length;
            items.forEach(function (el, n) {
                el.classList.toggle('is-active', n === index);
            });
            if (indexEl) indexEl.textContent = index + 1;
        }

        function start() {
            stop();
            timer = setInterval(function () { show(index + 1); }, 6000);
        }

        function stop() {
            if (timer) { clearInterval(timer); timer = null; }
        }

        box.querySelector('[data-role="announce-prev"]')
            .addEventListener('click', function () { show(index - 1); start(); });

        box.querySelector('[data-role="announce-next"]')
            .addEventListener('click', function () { show(index + 1); start(); });

        box.addEventListener('mouseenter', stop);
        box.addEventListener('mouseleave', start);

        start();
    };

})(window.App);
