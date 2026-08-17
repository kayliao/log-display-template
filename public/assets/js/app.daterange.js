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
(function (App) {
    'use strict';

    if (!App.once('daterange')) return;

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

        var startPicker = flatpickr(startEl, Object.assign({}, common, {
            onChange: function (dates) {
                if (!dates.length || !endPicker) return;

                var start = dates[0];

                // 結束日不能早於開始日
                endPicker.set('minDate', start);

                // 也不能超過區間上限
                if (maxDays > 0) {
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
                        App.toast('查詢區間最多 ' + maxDays + ' 天，結束日期已自動調整。', 'warning');
                    }
                }
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

        // 套用初始值的限制
        if (startEl.value) {
            startPicker.setDate(startEl.value, true);
        }

        bindPresets(box, startPicker, endPicker, maxDays);
    }

    /**
     * 常用區間快捷鍵。超過上限的選項直接隱藏，不要讓使用者點了才失望。
     */
    function bindPresets(box, startPicker, endPicker, maxDays) {
        var presets = box.querySelector('[data-role="presets"]');
        if (!presets) return;

        Array.prototype.forEach.call(presets.querySelectorAll('[data-days]'), function (btn) {
            var days = parseInt(btn.getAttribute('data-days'), 10);

            if (maxDays > 0 && days > maxDays) {
                btn.hidden = true;
                return;
            }

            btn.addEventListener('click', function () {
                var end   = new Date();
                var start = App.date.daysAgo(days - 1);

                // 先放寬限制再設定，否則新值會被舊的 min/max 擋掉
                endPicker.set('maxDate', null);
                startPicker.set('maxDate', null);

                startPicker.setDate(start, true);
                endPicker.setDate(end, true);
            });
        });
    }

    App.dateRange = { init: init };

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(
            document.querySelectorAll('[data-daterange-config]'),
            init
        );
    });

})(window.App);
