/**
 * 數字小卡的前端（stat_tile / stat_card 給了 api 的時候）。
 *
 * 沒給 api 的小卡是純 PHP 畫的靜態內容，這一支完全不會碰到它們。
 * 給了 api 就變成「可以重抓的一塊」：匯入完、按查詢之後重畫，
 * 使用者不用自己按 F5。
 *
 * 版面刻意跟 PHP 元件（app/Views/components/stat_tile.php、stat_card.php）
 * 一模一樣——第一次載入是 PHP 畫的、之後重抓是這裡畫的，
 * 兩邊長得不一樣的話現場會以為數字跳掉了。app.achievement.js 也是這個做法。
 *
 * 回應格式：{ "<field>": [ 跟 PHP 元件 items 一樣的陣列 ], "title": ..., "subtitle": ... }
 * field 由元件設定（預設 items），title / subtitle 有給才換。
 * 同一支 API 可以同時餵好幾張卡（各取各的 field），前端只會打一次
 * （App.http 的 shared，見 app.http.js）。達成率卡 app.achievement.js 也吃這一套。
 *
 * 對外方法：
 *   App.stat.get(id)                取得實例
 *   App.stat.reload(id, params)     重抓一次
 *   App.stat.reloadAll(ids, params) 一次重抓多張卡（匯入完、查詢條件列用）
 *   App.stat.primeAll(ids, params)  頁面載入時把預設條件交給卡片
 */
(function (App) {
    'use strict';

    var instances = {};

    /**
     * 數字格式化。規則跟 PHP 元件的 $fmt 一致：
     * 空值顯示破折號，非數字原樣輸出，沒指定 format 就不加工。
     */
    function fmt(value, format) {
        if (value === null || value === undefined || value === '') return '—';

        var n = Number(value);
        if (isNaN(n)) return String(value);

        switch (format) {
            case 'number':  return n.toLocaleString('zh-TW', { maximumFractionDigits: 0 });
            case 'decimal': return n.toLocaleString('zh-TW', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
            case 'percent': return n.toLocaleString('zh-TW', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%';
            default:        return String(value);
        }
    }

    /** 語意色只收英文字母，跟 PHP 元件的 preg_replace 同一個規則 */
    function tone(value) {
        return String(value || '').toLowerCase().replace(/[^a-z]/g, '');
    }

    /** 徽章。對應 components/badge.php，接受 {label, status|tone, soft, icon} */
    function badge(cfg) {
        var color = tone(cfg.status) || tone(cfg.tone) || 'muted';

        return '<span class="app-badge app-badge--' + color +
               (cfg.soft ? ' app-badge--soft' : '') + '">' +
               (cfg.icon ? '<i class="bi bi-' + App.esc(cfg.icon) + '"></i> ' : '') +
               App.esc(cfg.label || '') + '</span>';
    }

    /** 數字（含單位），或改成一顆徽章 */
    function valueHtml(item, unitClass) {
        if (item.badge) return badge(item.badge);

        return App.esc(fmt(item.value, item.format)) +
               (item.unit ? '<i class="' + unitClass + '">' + App.esc(item.unit) + '</i>' : '');
    }

    // ------------------------------------------------------------ stat_tile
    function renderTiles(items) {
        return items.map(function (item) {
            var url  = item.url || '';
            var tag  = url ? 'a' : 'div';
            var hue  = tone(item.tone);

            return '<' + tag + ' class="app-tile' + (url ? ' app-tile--link' : '') + '"' +
                       (url ? ' href="' + App.esc(url) + '"' : '') + '>' +

                       '<div class="app-tile__label">' +
                           (item.icon ? '<i class="bi bi-' + App.esc(item.icon) + '"></i>' : '') +
                           '<span>' + App.esc(item.label || '') + '</span>' +
                       '</div>' +

                       '<div class="app-tile__value' + (hue ? ' app-tile__value--' + hue : '') + '">' +
                           valueHtml(item, 'app-tile__unit') +
                       '</div>' +

                       (item.hint ? '<div class="app-tile__hint">' + App.esc(item.hint) + '</div>' : '') +
                   '</' + tag + '>';
        }).join('');
    }

    // ------------------------------------------------------------ stat_card
    function renderCard(items) {
        return items.map(function (item) {
            var hue  = tone(item.tone);
            var cls  = hue ? ' app-statcard__value--' + hue : '';
            var html = '<div class="app-statcard__item">' +
                           '<div class="app-statcard__row">' +
                               '<span class="app-statcard__label">' + App.esc(item.label || '') + '</span>' +
                               '<span class="app-statcard__value' + cls + '">' +
                                   valueHtml(item, 'app-statcard__unit') +
                                   deltaHtml(item) +
                               '</span>' +
                           '</div>' +
                           (item.hint ? '<div class="app-statcard__hint">' + App.esc(item.hint) + '</div>' : '');

            if (item.bar !== null && item.bar !== undefined && item.bar !== '') {
                var pct = Math.max(0, Math.min(100, Number(item.bar) || 0));

                html += '<div class="app-statcard__bar">' +
                            '<span class="app-statcard__bar-fill' +
                            (hue ? ' app-statcard__bar-fill--' + hue : '') +
                            '" style="width: ' + pct + '%"></span>' +
                        '</div>';
            }

            return html + '</div>';
        }).join('');
    }

    /** 跟上期的變化。只表示方向，不預設好壞——不良率上升不是好事 */
    function deltaHtml(item) {
        if (item.delta === null || item.delta === undefined || item.delta === '') return '';

        var delta = Number(item.delta) || 0;
        var dir   = delta > 0 ? 'up' : (delta < 0 ? 'down' : 'flat');
        var arrow = delta > 0 ? 'arrow-up' : (delta < 0 ? 'arrow-down' : 'dash');

        return '<i class="app-statcard__delta app-statcard__delta--' + dir + '">' +
                   '<i class="bi bi-' + arrow + '"></i>' +
                   App.esc(Math.abs(delta).toFixed(1)) +
               '</i>';
    }

    /**
     * 頁面上有沒有查詢條件列指名要重載這張卡。
     * 有的話就不自己查第一次——條件還沒送過來，那一次查詢會缺條件被後端擋下。
     * 判斷方式跟 app.table.js、app.achievement.js 一樣直接看 DOM。
     */
    function hasFilterOwner(id) {
        var forms = document.querySelectorAll('.app-filter[data-filter-target]');

        for (var i = 0; i < forms.length; i++) {
            var targets = String(forms[i].getAttribute('data-filter-target')).split(',');

            for (var j = 0; j < targets.length; j++) {
                if (targets[j].trim() === id) return true;
            }
        }

        return false;
    }

    function create(el) {
        var config = App.readConfig(el, 'data-stat-config');
        if (!config || !config.api || !config.id) return null;

        var listEl  = el.querySelector('[data-role="stat-list"]');
        var titleEl = el.querySelector('[data-role="stat-title"]');
        var subEl   = el.querySelector('[data-role="stat-subtitle"]');
        var params  = {};

        var instance = {
            id: config.id,
            config: config,
            el: el,
            loaded: false,

            /** 重抓一次（帶條件的話順便換掉條件） */
            reload: function (next) {
                if (next) params = next;

                var query = Object.assign({}, config.params || {}, params);

                /**
                 * block：遮罩蓋在這張卡上，不用全頁的那一層。
                 * shared：同面板好幾張卡指到同一支 API 時只打一次
                 *         （合併的規則在 app.http.js，達成率卡走的是同一套）。
                 */
                return App.http.get(config.api, query, { block: el, shared: true })
                    .then(function (data) {
                        instance.loaded = true;
                        instance.render(data || {});
                    })
                    .catch(function () {
                        // 錯誤訊息由 App.http 統一顯示，這裡只把卡片清空，
                        // 不要留著上一次的數字讓人以為是這次的結果
                        instance.render({});
                    });
            },

            /** 頁面載入時由查詢條件列呼叫：收下預設條件，auto 的才真的查 */
            prime: function (next) {
                if (next) params = next;
                if (config.auto !== false) instance.reload(null);
            },

            render: function (data) {
                var items = data[config.field || 'items'] || [];

                if (config.kind === 'card') {
                    if (listEl) listEl.innerHTML = renderCard(items);
                } else {
                    el.innerHTML = renderTiles(items);
                }

                if (titleEl && data.title    !== undefined) titleEl.textContent = data.title;
                if (subEl   && data.subtitle !== undefined) subEl.textContent   = data.subtitle;
            },

            params: function () {
                return params;
            }
        };

        instances[config.id] = instance;

        // 沒有條件列管它、又設定成自動查的，載入頁面時就查一次
        if (config.auto !== false && !hasFilterOwner(config.id)) {
            instance.reload(null);
        }

        return instance;
    }

    /** 把 'a,b' 或 ['a','b'] 統一成逐一處理 */
    function eachId(ids, fn) {
        (Array.isArray(ids) ? ids : String(ids || '').split(','))
            .map(function (s) { return s.trim(); })
            .filter(Boolean)
            .forEach(fn);
    }

    App.stat = {
        get: function (id) {
            return instances[id] || null;
        },

        reload: function (id, params) {
            var instance = instances[id];
            if (instance) instance.reload(params);
        },

        reloadAll: function (ids, params) {
            eachId(ids, function (id) {
                App.stat.reload(id, params);
            });
        },

        primeAll: function (ids, params) {
            eachId(ids, function (id) {
                var instance = instances[id];
                if (instance) instance.prime(params);
            });
        },

        init: function (el) {
            return create(el);
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-stat-config]'), create);
    });

})(window.App);
