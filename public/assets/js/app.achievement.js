/**
 * 達成率統整卡（achievement 元件的前端）。
 *
 * 給了 api 的卡片由這一支負責：向後端要 { items: [...] }，
 * 算出合計與佔比之後重畫卡片內容。
 * 要跟別張卡共用一支 API 就給 field（做法跟 stat_tile / stat_card 一樣），
 * 前端只會打一次（App.http 的 shared）。
 *
 * 算法與版面刻意跟 PHP 元件（app/Views/components/achievement.php）一模一樣——
 * 第一次載入是 PHP 畫的、之後重查是這裡畫的，
 * 兩邊長得不一樣的話現場會以為數字跳掉了。
 *
 * 對外方法：
 *   App.achievement.get(id)                取得實例
 *   App.achievement.reload(id, params)     帶新條件重查
 *   App.achievement.reloadAll(ids, params) 一次重查多張卡（查詢條件列用）
 *   App.achievement.primeAll(ids, params)  頁面載入時把預設條件交給卡片
 */
(function (App) {
    'use strict';

    if (!App.once('achievement')) return;

    // 跟 PHP 元件同一組色碼，重查之後分類顏色不會跳掉
    var PALETTE = ['#2563eb', '#7c3aed', '#0891b2', '#d97706', '#16a34a', '#db2777'];

    var instances = {};

    function num(value) {
        if (value === null || value === undefined || value === '' || isNaN(Number(value))) {
            return null;
        }

        return Number(value);
    }

    /** 數量：整數不補小數點，有小數才留一位（跟 PHP 的 number_format 對齊） */
    function qty(value) {
        if (value === null) return '—';

        return value.toLocaleString('zh-TW', {
            minimumFractionDigits: 0,
            maximumFractionDigits: Math.abs(value - Math.round(value)) < 0.05 ? 0 : 1
        });
    }

    function pct(value) {
        return value === null ? '—' : value.toFixed(1) + '%';
    }

    function toneOf(rate, config) {
        if (rate === null) return 'muted';
        if (rate >= config.target) return 'success';

        return rate >= config.warn ? 'warning' : 'danger';
    }

    function colorOf(value, index) {
        return /^#[0-9A-Fa-f]{3,8}$/.test(String(value || ''))
            ? value
            : PALETTE[index % PALETTE.length];
    }

    function diffClass(diff) {
        return diff < 0 ? 'down' : (diff > 0 ? 'up' : 'flat');
    }

    /**
     * 把後端給的 items 算成畫面要的樣子。
     * 合計一律是「把 items 加起來」，不接受後端另外送一份——
     * 兩份數字遲早會對不起來。
     */
    function compute(items, config) {
        var rows      = [];
        var sumPlan   = 0;
        var sumActual = 0;

        (items || []).forEach(function (item, i) {
            var plan   = num(item.plan);
            var actual = num(item.actual);

            sumPlan   += plan   || 0;
            sumActual += actual || 0;

            rows.push({
                label:  String(item.label === undefined ? '' : item.label),
                hint:   String(item.hint === undefined ? '' : item.hint),
                color:  colorOf(item.color, i),
                plan:   plan,
                actual: actual,
                // 預計 0 不算達成率：沒有目標就沒有達成率可言
                rate:   (plan !== null && plan > 0) ? ((actual || 0) / plan * 100) : null,
                diff:   (plan === null && actual === null) ? null : ((actual || 0) - (plan || 0))
            });
        });

        var base = config.share === 'plan' ? sumPlan : sumActual;

        rows.forEach(function (row) {
            var value = config.share === 'plan' ? row.plan : row.actual;
            row.share = base > 0 ? ((value || 0) / base * 100) : null;
        });

        return {
            rows: rows,
            base: base,
            sumPlan: sumPlan,
            sumActual: sumActual,
            totalRate: sumPlan > 0 ? (sumActual / sumPlan * 100) : null,
            totalDiff: sumActual - sumPlan
        };
    }

    function renderTotal(data, config) {
        var tone  = toneOf(data.totalRate, config);
        var width = data.totalRate === null ? 0 : Math.max(0, Math.min(100, data.totalRate));
        var unit  = App.esc(config.unit || '');
        // 單位跟著數字走（17,600 片），不另外開一格寫「單位：片」
        var unitTag = unit === '' ? '' : '<i class="app-achv__unit">' + unit + '</i>';
        var gap   = '';

        if (data.sumPlan > 0) {
            gap = data.totalDiff < 0 ? ('還差 ' + qty(Math.abs(data.totalDiff)) + unit)
                : (data.totalDiff > 0 ? ('超出 ' + qty(data.totalDiff) + unit) : '剛好達標');
        }

        var html =
            '<div class="app-achv__total">' +
                '<div class="app-achv__total-head">' +
                    '<span class="app-achv__total-label">' + App.esc(config.totalLabel || '總達成率') + '</span>' +
                    '<span class="app-achv__total-rate app-achv__total-rate--' + tone + '">' + pct(data.totalRate) + '</span>' +
                    (gap ? '<span class="app-achv__total-gap">' + gap + '</span>' : '') +
                '</div>' +

                '<div class="app-achv__meter">' +
                    '<span class="app-achv__meter-fill app-achv__meter-fill--' + tone + '" style="width: ' + width.toFixed(2) + '%"></span>' +
                '</div>' +

                '<div class="app-achv__total-nums">' +
                    cell('總預計', qty(data.sumPlan) + unitTag) +
                    cell('總實際', qty(data.sumActual) + unitTag) +
                    cell('差異', (data.totalDiff > 0 ? '+' : '') + qty(data.totalDiff) + unitTag,
                         'app-achv__diff app-achv__diff--' + diffClass(data.totalDiff)) +
                '</div>';

        // 組成比例：兩項以上才有意義
        if (data.rows.length > 1 && data.base > 0) {
            var segs = '';
            var legend = '<span class="app-achv__legend-title">' +
                         (config.share === 'plan' ? '佔預計' : '佔實際') + '</span>';

            data.rows.forEach(function (row) {
                if (row.share !== null && row.share > 0) {
                    segs += '<span class="app-achv__mix-seg" style="width: ' + row.share.toFixed(2) +
                            '%; background: ' + App.esc(row.color) + '" title="' +
                            App.esc(row.label) + ' ' + pct(row.share) + '"></span>';
                }

                legend += '<span class="app-achv__legend-item">' +
                          '<i class="app-achv__dot" style="background: ' + App.esc(row.color) + '"></i>' +
                          App.esc(row.label) + ' <b>' + pct(row.share) + '</b></span>';
            });

            html += '<div class="app-achv__mix">' +
                        '<div class="app-achv__mix-bar">' + segs + '</div>' +
                        '<div class="app-achv__legend">' + legend + '</div>' +
                    '</div>';
        }

        return html + '</div>';
    }

    function cell(label, value, className) {
        return '<div class="app-achv__cell">' +
                   '<span class="app-achv__cell-label">' + App.esc(label) + '</span>' +
                   '<b' + (className ? ' class="' + className + '"' : '') + '>' + value + '</b>' +
               '</div>';
    }

    function renderList(data, config) {
        if (data.rows.length === 0) {
            return '<div class="app-achv__empty">' + App.esc(config.empty || '沒有可以統計的資料') + '</div>';
        }

        var shareText = config.share === 'plan' ? '佔預計' : '佔實際';

        var html = '<div class="app-achv__list">' +
                   '<div class="app-achv__row app-achv__row--head">' +
                       '<div class="app-achv__name">項目</div>' +
                       '<div class="app-achv__cell"><span>預計</span></div>' +
                       '<div class="app-achv__cell"><span>實際</span></div>' +
                       '<div class="app-achv__cell"><span>差異</span></div>' +
                       '<div class="app-achv__cell"><span>達成率</span></div>' +
                       '<div class="app-achv__cell"><span>' + App.esc(shareText) + '</span></div>' +
                   '</div>';

        data.rows.forEach(function (row) {
            var tone  = toneOf(row.rate, config);
            var width = row.rate === null ? 0 : Math.max(0, Math.min(100, row.rate));
            var diff  = row.diff === null ? 0 : row.diff;

            html += '<div class="app-achv__item">' +
                        '<div class="app-achv__row">' +
                            '<div class="app-achv__name">' +
                                '<i class="app-achv__dot" style="background: ' + App.esc(row.color) + '"></i>' +
                                '<span>' + App.esc(row.label) + '</span>' +
                                (row.hint ? '<em class="app-achv__hint">' + App.esc(row.hint) + '</em>' : '') +
                            '</div>' +
                            cell('預計', qty(row.plan)) +
                            cell('實際', qty(row.actual)) +
                            cell('差異', (diff > 0 ? '+' : '') + qty(row.diff),
                                 'app-achv__diff app-achv__diff--' + diffClass(diff)) +
                            cell('達成率', pct(row.rate), 'app-achv__rate app-achv__rate--' + tone) +
                            cell(shareText, pct(row.share), 'app-achv__share') +
                        '</div>' +
                        '<div class="app-achv__bar">' +
                            '<span class="app-achv__bar-fill app-achv__bar-fill--' + tone +
                            '" style="width: ' + width.toFixed(2) + '%"></span>' +
                        '</div>' +
                    '</div>';
        });

        return html + '</div>';
    }

    /**
     * 頁面上有沒有查詢條件列指名要重載這張卡。
     * 有的話就不自己查第一次——條件還沒送過來，那一次查詢會缺日期被後端擋下。
     * 判斷方式跟 app.table.js 一樣直接看 DOM，兩邊誰先初始化都不影響。
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
        var config = App.readConfig(el, 'data-achv-config');
        if (!config || !config.api) return null;

        config.target = config.target === undefined ? 100 : Number(config.target);
        config.warn   = config.warn   === undefined ? 90  : Number(config.warn);

        var body     = el.querySelector('[data-role="achv-body"]');
        var titleEl  = el.querySelector('[data-role="achv-title"]');
        var subEl    = el.querySelector('[data-role="achv-subtitle"]');
        var footEl   = el.querySelector('[data-role="achv-foot"]');
        var params   = {};

        var instance = {
            id: config.id,
            config: config,
            el: el,
            loaded: false,

            /** 帶新條件重查 */
            reload: function (next) {
                if (next) params = next;

                var query = Object.assign({}, config.params || {}, params);

                /**
                 * block：遮罩蓋在這張卡上，不用全頁的那一層。
                 * shared：跟數字小卡指到同一支 API 時只打一次
                 *         （合併的規則在 app.http.js，做法同 app.stat.js）。
                 */
                return App.http.get(config.api, query, { block: el, shared: true })
                    .then(function (data) {
                        instance.loaded = true;
                        instance.render(data || {});
                    })
                    .catch(function () {
                        // 錯誤訊息由 App.http 統一顯示，這裡只把卡片清成空狀態，
                        // 不要留著上一次查詢的數字讓人以為是這次的結果。
                        // 傳空物件而不是 { items: [] }：卡片可能設了別的 field
                        instance.render({});
                    });
            },

            /** 頁面載入時由查詢條件列呼叫：收下預設條件，auto 的才真的查 */
            prime: function (next) {
                if (next) params = next;
                if (config.auto !== false) instance.reload(null);
            },

            render: function (data) {
                /**
                 * 從回應的哪一個鍵取項目。規則跟 app.stat.js 一樣：
                 * 預設 items，一支 API 要同時餵好幾張卡時才需要指定
                 * （水化的 today.php 一包裡有 tiles / cycles / achv）。
                 */
                var computed = compute(data[config.field || 'items'], config);

                if (body) {
                    body.innerHTML = config.summary === 'bottom'
                        ? renderList(computed, config) + renderTotal(computed, config)
                        : renderTotal(computed, config) + renderList(computed, config);
                }

                if (titleEl && data.title !== undefined)    titleEl.textContent = data.title;
                if (subEl   && data.subtitle !== undefined) subEl.textContent   = data.subtitle;

                if (footEl && data.footer !== undefined) {
                    footEl.textContent = data.footer;
                    footEl.classList.toggle('d-none', !data.footer);
                }
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

    App.achievement = {
        get: function (id) {
            return instances[id] || null;
        },

        reload: function (id, params) {
            var instance = instances[id];
            if (instance) instance.reload(params);
        },

        reloadAll: function (ids, params) {
            eachId(ids, function (id) {
                App.achievement.reload(id, params);
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
        Array.prototype.forEach.call(document.querySelectorAll('[data-achv-config]'), create);
    });

})(window.App);
