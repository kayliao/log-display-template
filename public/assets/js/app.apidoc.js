/**
 * 對外 API 說明書頁（/pages/dev/api_docs.php）。
 *
 * 只有這一頁會載入，做三件小事：
 *   1. 程式碼區塊的「複製」按鈕
 *   2. 匯出面板的全選／連動，以及「一支都沒勾」的擋門
 *   3. 左邊索引的目前位置高亮
 *
 * 這一頁的內容全部是後端輸出好的靜態 HTML，所以這裡不打任何 API。
 */
window.App = window.App || {};

(function (App) {
    'use strict';

    // 同一支檔案被載入兩次時，第二次直接跳出（原因見 app.core.js 開頭）
    App.__loaded = App.__loaded || {};
    if (App.__loaded.apidoc) return;
    App.__loaded.apidoc = true;

    /**
     * 複製文字到剪貼簿。
     *
     * navigator.clipboard 只在 https 或 localhost 才有，現場是 http 的內網位址，
     * 所以那條路多半是走不到的——一定要留 execCommand 這條舊路，
     * 否則現場按複製會完全沒有反應（而且不會報錯，最難查的那一種）。
     */
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(function () { return true; },
                                                            function () { return legacyCopy(text); });
        }

        return Promise.resolve(legacyCopy(text));
    }

    function legacyCopy(text) {
        var ta = document.createElement('textarea');

        ta.value = text;
        // 放在畫面外，避免捲動位置被拉走
        ta.style.position = 'fixed';
        ta.style.top      = '-1000px';
        ta.setAttribute('readonly', 'readonly');

        document.body.appendChild(ta);
        ta.select();
        ta.setSelectionRange(0, ta.value.length);

        var ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (e) {
            ok = false;
        }

        document.body.removeChild(ta);

        return ok;
    }

    document.addEventListener('DOMContentLoaded', function () {

        // --- 1. 複製按鈕 ---
        Array.prototype.forEach.call(document.querySelectorAll('[data-copy]'), function (btn) {
            btn.addEventListener('click', function () {
                var block = btn.closest('.api-code');
                var body  = block && block.querySelector('.api-code__body');
                if (!body) return;

                copyText(body.textContent).then(function (ok) {
                    if (!ok) {
                        App.toast('這個瀏覽器不讓程式複製，請自己選取後按 Ctrl+C。', 'warning');
                        return;
                    }

                    var label = btn.querySelector('span');
                    if (!label) return;

                    // 按鈕自己回報結果就好，不用跳 toast——一頁上有十幾顆按鈕
                    label.textContent = '已複製';
                    btn.classList.add('is-done');

                    setTimeout(function () {
                        label.textContent = '複製';
                        btn.classList.remove('is-done');
                    }, 1500);
                });
            });
        });

        // --- 2. 匯出面板 ---
        var form = document.querySelector('[data-api-export]');

        if (form) {
            var toggle = form.querySelector('[data-export-toggle]');
            var boxes  = Array.prototype.slice.call(form.querySelectorAll('input[name="keys[]"]'));

            function syncToggle() {
                var checked = boxes.filter(function (b) { return b.checked; }).length;

                toggle.checked       = (checked === boxes.length);
                // 勾了一部分時顯示成「半選」，比只有勾／沒勾兩種狀態好懂
                toggle.indeterminate = (checked > 0 && checked < boxes.length);
            }

            toggle.addEventListener('change', function () {
                boxes.forEach(function (b) { b.checked = toggle.checked; });
                toggle.indeterminate = false;
            });

            boxes.forEach(function (b) { b.addEventListener('change', syncToggle); });

            form.addEventListener('submit', function (e) {
                if (boxes.some(function (b) { return b.checked; })) return;

                // 全部沒勾就送出的話，後端會當成「整份匯出」，
                // 但使用者剛剛明明是把它們一個個取消掉的，那不會是他要的結果
                e.preventDefault();
                App.toast('請至少勾選一支要匯出的 API。', 'warning');
            });

            syncToggle();
        }

        // --- 3. 左邊索引高亮 ---
        var links = Array.prototype.slice.call(document.querySelectorAll('.api-nav__link'));

        function markCurrent(hash) {
            links.forEach(function (a) {
                a.classList.toggle('is-current', a.getAttribute('href') === hash);
            });
        }

        links.forEach(function (a) {
            a.addEventListener('click', function () { markCurrent(a.getAttribute('href')); });
        });

        markCurrent(window.location.hash || (links[0] && links[0].getAttribute('href')));
    });

})(window.App);
