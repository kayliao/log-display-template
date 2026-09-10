/**
 * 日期區間選擇器。
 *
 * 底層是 flatpickr（單一 JS + CSS，沒有其他相依，適合離線佈署）。
 *
 * 這一層做的事是「限制可選範圍」：
 *   選了開始日之後，結束日的日曆會直接把超出上限的日期變成不可點，
 *   而不是等使用者選完才跳警告——現場人員最討厭選完才被退件。
 *
 * 上限來自後端 config/app.php 的 query_range，
 * 由 date_range 元件寫在 data-daterange-config 上。
 * 後端 Request::dateRange() 會再驗一次，避免直接打 API 繞過前端限制。
 */
window.App = window.App || {};

(function (App) {
    'use strict';

    // 同一支檔案被載入兩次時，第二次直接跳出（原因見 app.core.js 開頭）
    App.__loaded = App.__loaded || {};
    if (App.__loaded.daterange) return;
    App.__loaded.daterange = true;

    function init(box) {
        var config = App.readConfig(box, 'data-daterange-config') || {};
        var maxDays = parseInt(config.maxDays, 10) || 0;

        var startEl = box.querySelector('[data-role="range-start"]');
        var endEl   = box.querySelector('[data-role="range-end"]');
        if (!startEl || !endEl) return;

        var common = {
            dateFormat: 'Y-m-d',
            locale: 'zh_tw',
            allowInput: true,
            disableMobile: true,
            maxDate: config.maxDate || null,
            minDate: config.minDate || null
        };

        var endPicker = null;

        /**
         * 把「開始日決定了結束日能選的範圍」這條規則套上去。
         *
         * 抽成一支是因為有三個時機都要用它：使用者改開始日、按快捷鍵、
         * 以及按「清除」還原預設值之後——三個地方各寫一次的話，
         * 遲早會有一個忘記跟上。
         *
         * warn = false 表示這次是程式自己在還原，不是使用者選錯，
         * 不要跳提示嚇人。
         */
        function applyStartLimits(start, warn) {
            if (!start || !endPicker) return;

            endPicker.set('minDate', start);

            if (maxDays <= 0) return;

            var limit = new Date(start);
            limit.setDate(limit.getDate() + maxDays - 1);

            // 上限和「不能選未來」取比較嚴格的那個
            var maxDate = config.maxDate === 'today' && limit > new Date()
                ? new Date()
                : limit;

            endPicker.set('maxDate', maxDate);

            // 原本選的結束日如果已經超出新範圍，往回拉到上限
            var current = endPicker.selectedDates[0];

            if (current && current > maxDate) {
                endPicker.setDate(maxDate, true);

                if (warn) {
                    App.toast('查詢區間最多 ' + maxDays + ' 天，結束日期已自動調整。', 'warning');
                }
            }
        }

        var startPicker = flatpickr(startEl, Object.assign({}, common, {
            onChange: function (dates) {
                if (dates.length) applyStartLimits(dates[0], true);
            }
        }));

        endPicker = flatpickr(endEl, Object.assign({}, common, {
            minDate: startEl.value || null,

            onChange: function (dates) {
                if (!dates.length) return;
                // 開始日不能晚於結束日
                startPicker.set('maxDate', dates[0]);
            }
        }));

        /**
         * ★ 把兩格的互相牽制放回「原始設定」，不是放成無限制。
         *
         *   兩個日曆會互相夾：選了開始日，結束日的 maxDate 就被鎖到
         *   開始日 + 上限；選了結束日，開始日的 maxDate 就被鎖到結束日。
         *   要塞一個新值進去之前一定得先鬆開，否則 flatpickr 會**默默拒絕**
         *   ——沒有錯誤、沒有提示，畫面上就是「按了沒反應」。
         *
         *   鬆開成 null 是不行的，那樣「不能選未來」也一起沒了；
         *   要放回 common 裡那組原始限制。
         */
        function release() {
            startPicker.set('minDate', common.minDate || null);
            startPicker.set('maxDate', common.maxDate || null);
            endPicker.set('minDate', common.minDate || null);
            endPicker.set('maxDate', common.maxDate || null);
        }

        /** 依目前兩格的值，把互相牽制重新套上去 */
        function couple() {
            if (startEl.value) applyStartLimits(startPicker.parseDate(startEl.value), false);
            if (endEl.value)   startPicker.set('maxDate', endPicker.parseDate(endEl.value));
        }

        /**
         * 給查詢條件列的「清除」用（見 app.filter.js）。
         * 它要先 release() 才設得進預設值，設完再 couple() 把規則裝回去。
         */
        box._appRange = { release: release, couple: couple };

        // 套用初始值的限制
        if (startEl.value) {
            startPicker.setDate(startEl.value, true);
        }

        bindPresets(box, startPicker, endPicker, maxDays, release);
    }

    /**
     * 常用區間快捷鍵。超過上限的選項直接隱藏，不要讓使用者點了才失望。
     *
     * 隱藏到只剩一顆（或一顆都不剩）時，整列跟著收起來——
     * 上限一天的頁面只會剩下「今天」，而畫面上本來就已經是今天，
     * 留一顆按了不會有任何變化的按鈕比沒有還糟。
     */
    function bindPresets(box, startPicker, endPicker, maxDays, release) {
        var presets = box.querySelector('[data-role="presets"]');
        if (!presets) return;

        var visible = 0;

        Array.prototype.forEach.call(presets.querySelectorAll('[data-days]'), function (btn) {
            var days = parseInt(btn.getAttribute('data-days'), 10);

            if (maxDays > 0 && days > maxDays) {
                btn.hidden = true;
                return;
            }

            visible++;

            btn.addEventListener('click', function () {
                var end   = new Date();
                var start = App.date.daysAgo(days - 1);

                // 先放寬限制再設定，否則新值會被舊的 min/max 擋掉
                release();

                startPicker.setDate(start, true);
                endPicker.setDate(end, true);
            });
        });

        /**
         * 「不限」：把兩格清空（只有 blank 模式的區間才有這一顆）。
         *
         * 清空之後也要把互相牽制的 min/max 放掉，
         * 不然下一次挑日期會被上一次留下來的限制擋住。
         */
        var clear = presets.querySelector('[data-role="clear"]');

        if (clear) {
            visible++;

            clear.addEventListener('click', function () {
                startPicker.clear();
                endPicker.clear();
                release();
            });
        }

        // 只剩一顆（或零顆）就把整列收起來
        if (visible <= 1) presets.hidden = true;
    }

    App.dateRange = { init: init };

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(
            document.querySelectorAll('[data-daterange-config]'),
            init
        );
    });

})(window.App);
