/**
 * 合計列。
 *
 * 掛在一張可勾選的表格下面，數字由後端算好送過來（原因見 sum_bar 元件的說明）。
 *
 * 什麼時候會重新計算：
 *   - 查詢條件改變（跟表格一樣由條件列的 target 觸發）
 *   - 使用者勾選或取消勾選（監聽表格發出的 app:table:select）
 *
 * 對外方法：
 *   App.sum.reloadAll(ids, params)
 *   App.sum.primeAll(ids, params)
 */
window.App = window.App || {};

(function (App) {
    'use strict';

    // 同一支檔案被載入兩次時，第二次直接跳出（原因見 app.core.js 開頭）
    App.__loaded = App.__loaded || {};
    if (App.__loaded.sum) return;
    App.__loaded.sum = true;

    var instances = {};

    function create(el) {
        var config = App.readConfig(el, 'data-sum-config');
        if (!config) return null;

        var state = { params: {}, selected: [] };

        var instance = {
            id: config.id,
            config: config,

            reload: function (params) {
                if (params) state.params = params;

                if (!config.api) return;

                var query = Object.assign({}, config.params || {}, state.params);

                // 有勾選就算勾起來的，沒有就算整個查詢結果
                if (state.selected.length) {
                    query.selected = state.selected.join(',');
                }

                App.http.get(config.api, query, { block: el, loading: false })
                    .then(function (data) { render(el, config, data); })
                    .catch(function () { /* 錯誤訊息 App.http 已經跳過了 */ });
            },

            /** 勾選變動：只更新數字，不動查詢條件 */
            setSelected: function (ids) {
                state.selected = ids || [];

                var count = el.querySelector('[data-role="sum-count"]');
                if (count) count.textContent = state.selected.length;

                var hint = el.querySelector('[data-role="sum-hint"]');
                if (hint) hint.style.display = state.selected.length ? 'none' : '';

                instance.reload();
            }
        };

        instances[config.id] = instance;

        bindTable(el, config, instance);

        return instance;
    }

    /**
     * 跟表格連動。
     *
     * 用 DOM 事件而不是直接呼叫 App.table，是為了讓合計列不必知道
     * 表格是怎麼實作的 —— 之後換掉表格底層，這裡不用跟著改。
     */
    function bindTable(el, config, instance) {
        if (!config.table) return;

        document.addEventListener('app:table:select', function (e) {
            if (!e.detail || e.detail.id !== config.table) return;

            instance.setSelected(e.detail.selected);
        });

        var all = el.querySelector('[data-role="sum-all"]');
        if (all) {
            all.addEventListener('click', function () {
                App.table.selectAllMatching(config.table);
            });
        }

        var clear = el.querySelector('[data-role="sum-clear"]');
        if (clear) {
            clear.addEventListener('click', function () {
                App.table.clearSelection(config.table);
            });
        }
    }

    function render(el, config, data) {
        (config.keys || []).forEach(function (keys, row) {
            keys.forEach(function (key, col) {
                var cell = el.querySelector(
                    '[data-role="sum-cell"][data-row="' + row + '"][data-col="' + col + '"]'
                );
                if (!cell) return;

                cell.textContent = App.format.number(data[key] || 0);
            });
        });
    }

    function eachId(ids, fn) {
        (Array.isArray(ids) ? ids : String(ids).split(','))
            .map(function (s) { return s.trim(); })
            .filter(Boolean)
            .forEach(fn);
    }

    App.sum = {
        get: function (id) {
            return instances[id] || null;
        },

        reloadAll: function (ids, params) {
            eachId(ids, function (id) {
                if (instances[id]) instances[id].reload(params);
            });
        },

        primeAll: function (ids, params) {
            eachId(ids, function (id) {
                if (instances[id]) instances[id].reload(params);
            });
        },

        init: function (el) {
            return create(el);
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-sum-config]'), create);
    });

})(window.App);
