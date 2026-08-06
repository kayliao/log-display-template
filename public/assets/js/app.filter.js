/**
 * 查詢條件列。
 *
 * 把「按查詢 → 收集欄位 → 重新載入表格」這段固定流程集中處理，
 * 每個報表頁就不用各寫一次 onclick。
 *
 * 條件列上的 data-filter-target 指定要重新載入哪些表格（可用逗號分隔多個）。
 *
 * 行為：
 *   - 按 Enter 等於按查詢
 *   - 查詢送出時整列鎖住，避免連點造成重複查詢
 *   - 清除會還原成頁面載入時的預設值，並重新查一次
 */
(function (App) {
    'use strict';

    function init(form) {
        var targets = form.getAttribute('data-filter-target') || '';

        // 記住初始值，「清除」才知道要還原成什麼
        var defaults = App.serialize(form);

        function submit() {
            var params = App.serialize(form);

            form.classList.add('is-busy');

            App.table.reloadAll(targets, params);

            // 表格是非同步載入的，這裡用短暫延遲解除鎖定即可，
            // 真正的載入狀態由表格自己的區塊遮罩顯示
            setTimeout(function () { form.classList.remove('is-busy'); }, 300);

            // 記在網址上，重新整理或轉貼連結時條件還在
            updateUrl(params);
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            submit();
        });

        var submitBtn = form.querySelector('[data-role="filter-submit"]');
        if (submitBtn) submitBtn.addEventListener('click', submit);

        var resetBtn = form.querySelector('[data-role="filter-reset"]');
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                Array.prototype.forEach.call(form.querySelectorAll('[name]'), function (el) {
                    el.value = defaults[el.name] !== undefined ? defaults[el.name] : '';
                    // 日期欄位由 flatpickr 接管，要透過它的 API 設定才會同步
                    if (el._flatpickr) el._flatpickr.setDate(el.value, false);
                });
                submit();
            });
        }

        // 在輸入框按 Enter 直接查詢
        form.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
                e.preventDefault();
                submit();
            }
        });

        // 頁面載入時，把預設條件先送給表格（auto=true 的表格會立刻查）
        App.table.reloadAll(targets, defaults);
    }

    /**
     * 把查詢條件同步到網址列，不重新載入頁面。
     */
    function updateUrl(params) {
        if (!window.history || !window.history.replaceState) return;

        var qs = Object.keys(params)
            .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); })
            .join('&');

        window.history.replaceState(null, '', qs ? '?' + qs : window.location.pathname);
    }

    App.filter = { init: init };

    document.addEventListener('DOMContentLoaded', function () {
        // 表格要先初始化好，這裡才叫得動它，所以延到下一個事件迴圈
        setTimeout(function () {
            Array.prototype.forEach.call(document.querySelectorAll('.app-filter'), init);
        }, 0);
    });

})(window.App);
