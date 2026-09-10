/**
 * 時間軸甘特圖。
 *
 * 用一般的 HTML 元素畫，不是 SVG，也沒有引入繪圖套件。
 *
 * ★ 為什麼是 HTML 不是 SVG
 *
 *   兩者都能畫，但這張圖有兩個需求 SVG 做不到：
 *
 *   1. 橫向捲動時，左邊的機台名稱要留在原地。
 *      SVG 是一張整體的畫布，捲出去就是捲出去；HTML 用
 *      `position: sticky` 一行就解決，看到哪一條都知道是哪一台機台。
 *   2. 上方的時間刻度要在垂直捲動時留在原地。同理。
 *
 *   另外還撿到三個好處：
 *   - 位置與寬度用**百分比**表示，視窗縮放、放大倍率改變都由瀏覽器自己算，
 *     不用重畫（SVG 版本每次 resize 都要重新產生整棵樹）
 *   - 文字太長可以 `text-overflow: ellipsis`，SVG 沒有這個
 *   - 列標題可以是真正的 `<button>`，鍵盤 Tab 得到、有焦點框
 *
 * 對外方法：
 *   App.gantt.get(id)                取得實例
 *   App.gantt.reload(id, params)     重抓一次
 *   App.gantt.reloadAll(ids, params) 一次重抓多張（查詢條件列用）
 *
 * 座標換算只有一條規則：
 *   left / width = (區段時間 - 時間軸左界) / 總秒數 × 100%
 * 用「秒」算，不用筆數也不用平均值——甘特圖的意義就在長短。
 *
 * API 回傳格式見 app/Views/components/gantt.php 的檔頭。
 */
window.App = window.App || {};

(function (App) {
    'use strict';

    // 同一支檔案被載入兩次時，第二次直接跳出（原因見 app.core.js 開頭）
    App.__loaded = App.__loaded || {};
    if (App.__loaded.gantt) return;
    App.__loaded.gantt = true;

    /** id => 實例。查詢條件列要叫某一張圖重載時用得到。 */
    var instances = {};

    /**
     * 區段要多寬才把時數寫在上面。
     *
     * 用百分比而不是像素：像素會隨放大倍率變動，那樣「放大之後字才跑出來」
     * 會讓人以為資料變了。用百分比的話，同一段在任何倍率下的行為一致，
     * 想看細節就把滑鼠移上去，tooltip 永遠是完整的。
     */
    var LABEL_MIN_PCT = 4;

    /**
     * 後端一律送 'YYYY-MM-DD HH:MM:SS'。
     * Safari 不吃中間的空白，要換成 T 才 parse 得出來。
     */
    function parseTime(value) {
        if (!value) return NaN;
        return new Date(String(value).replace(' ', 'T')).getTime();
    }

    /** 秒數轉成人看得懂的長度：3h05m / 12m / 45s */
    function humanDuration(seconds) {
        var s = Math.max(0, Math.round(seconds));

        if (s < 60) return s + 's';

        var m = Math.floor(s / 60);
        if (m < 60) return m + 'm';

        var h = Math.floor(m / 60);
        var rest = m % 60;

        return h + 'h' + (rest < 10 ? '0' : '') + rest + 'm';
    }

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    /**
     * 依時間跨度挑刻度間隔。
     *
     * 固定間隔不行：查一天要看到小時，查一個月每小時一根線會糊成一片。
     * 目標是「刻度數落在 16 根以內」，由小到大挑第一個符合的。
     */
    function pickTick(spanMs) {
        var HOUR = 3600 * 1000;
        var candidates = [
            HOUR, 2 * HOUR, 3 * HOUR, 6 * HOUR, 12 * HOUR,
            24 * HOUR, 2 * 24 * HOUR, 7 * 24 * HOUR
        ];

        for (var i = 0; i < candidates.length; i++) {
            if (spanMs / candidates[i] <= 16) return candidates[i];
        }

        return candidates[candidates.length - 1];
    }

    /** 刻度文字：整點寫時分，跨日或午夜寫日期 */
    function tickLabel(ms, tickMs) {
        var d = new Date(ms);

        if (tickMs >= 24 * 3600 * 1000) {
            return (d.getMonth() + 1) + '/' + d.getDate();
        }

        if (d.getHours() === 0 && d.getMinutes() === 0) {
            return (d.getMonth() + 1) + '/' + d.getDate();
        }

        return pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    /**
     * 頁面上有沒有查詢條件列指名要重新載入這張圖。
     *
     * 有的話，第一次的查詢條件要由條件列提供（它會在頁面載入時呼叫 prime），
     * 圖自己不能搶先打一次沒帶條件的 API——那次請求會因為缺少必填的
     * 日期區間被後端擋下，使用者一進頁面就看到「請選擇查詢的起訖日期」。
     *
     * 直接看 DOM 而不是靠註冊，條件列與圖各自初始化的先後就不影響結果。
     * 作法與 app.table.js 的 hasFilterOwner() 一致。
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

    function Gantt(wrap) {
        var config = App.readConfig(wrap, 'data-gantt-config');
        if (!config) return null;

        var canvas = wrap.querySelector('[data-role="gantt-canvas"]');

        var zoom = 1;
        var data = { start: null, end: null, rows: [], summary: {} };

        /**
         * 綁工具列按鈕。
         *
         * ★ 找不到就跳過，不要讓整支 JS 死掉。
         *
         *   原本是 wrap.querySelector('[data-role="..."]').addEventListener(...)，
         *   元件樣板少一顆按鈕就丟 TypeError，而那個例外會打斷後面所有的
         *   初始化（分頁、下鑽、連動下拉全部不會綁）。少一顆按鈕不能用是
         *   小事，整張圖不會動是大事。
         */
        function bind(role, handler) {
            var el = wrap.querySelector('[data-role="' + role + '"]');

            if (el) {
                el.addEventListener('click', handler);
            }
        }

        function colorOf(code) {
            var meta = config.legend[String(code)];
            return meta ? meta.color : 'var(--eq-status-4)';
        }

        function labelOf(code) {
            var meta = config.legend[String(code)];
            return meta ? meta.label : String(code);
        }

        function render() {
            var start = parseTime(data.start);
            var end   = parseTime(data.end);

            if (!data.rows || data.rows.length === 0 || isNaN(start) || isNaN(end) || end <= start) {
                canvas.innerHTML = '<div class="app-gantt__empty">' + App.esc(config.empty) + '</div>';
                return;
            }

            var span = end - start;

            /** 時間換算成「佔時間軸的百分之幾」 */
            function pctOf(ms) {
                return (ms - start) / span * 100;
            }

            var html = '';

            // --- 時間刻度（垂直捲動時固定在上方）---
            var tickMs    = pickTick(span);
            var firstTick = Math.ceil(start / tickMs) * tickMs;

            var ticks = '';
            var lines = '';

            for (var t = firstTick; t <= end; t += tickMs) {
                var pct = pctOf(t);

                /**
                 * 頭尾兩個刻度不要置中。
                 *
                 * 刻度文字預設是 translateX(-50%)（以刻度線為中心），
                 * 但最左邊那個的左半邊會落到負座標——那裡是左上角那格
                 * （.gantt__corner，sticky 而且是不透明的），**整個被蓋掉**，
                 * 看起來就是第一個時間標籤不見了。最右邊那個同理會溢出去。
                 *
                 * 所以第一個靠左貼齊、最後一個靠右貼齊，中間的照舊置中。
                 */
                var tickCls = 'gantt-tick';

                if (pct <= 0.01) {
                    tickCls += ' gantt-tick--first';
                } else if (pct >= 99.99) {
                    tickCls += ' gantt-tick--last';
                }

                ticks += '<span class="' + tickCls + '" style="left:' + pct + '%">'
                       + App.esc(tickLabel(t, tickMs)) + '</span>';

                /**
                 * 格線畫在整張圖的疊層上，不是每一列各畫一次——
                 * 八十列各畫十六條就是一千兩百多個元素，而且看起來一模一樣。
                 */
                lines += '<i class="gantt-gridline" style="left:' + pct + '%"></i>';
            }

            /**
             * 「現在」的位置。
             * 查今天的時候，一條線就能看出哪些是已經發生的、右邊那片空白是還沒到，
             * 沒有這條線的話很容易把「還沒到」誤讀成「機台沒動」。
             */
            var nowMs  = Date.now();
            var nowBar = (nowMs > start && nowMs < end)
                ? '<i class="gantt-now" style="left:' + pctOf(nowMs) + '%" title="現在"></i>'
                : '';

            html += '<div class="gantt__head">'
                  +   '<div class="gantt__corner"></div>'
                  +   '<div class="gantt__ticks">' + ticks + '</div>'
                  + '</div>';

            // --- 每一列 ---
            html += '<div class="gantt__body">';

            data.rows.forEach(function (row) {
                var bars = '';

                (row.bars || []).forEach(function (bar) {
                    var s = Math.max(parseTime(bar.start), start);
                    var e = Math.min(parseTime(bar.end), end);

                    if (isNaN(s) || isNaN(e) || e <= s) return;

                    var left    = pctOf(s);
                    var width   = pctOf(e) - left;
                    var seconds = (e - s) / 1000;

                    var tip = bar.tip || (
                        (row.label || row.key) +
                        '\n' + labelOf(bar.code) + '　' + humanDuration(seconds) +
                        '\n' + bar.start + ' ~ ' + bar.end
                    );

                    /**
                     * 區段裡的持續時間文字。預設關閉（config.barLabel）。
                     *
                     * 長度本身就是那個數字的圖形版，再印一次是同一件事講兩次；
                     * 而且只有夠寬的區段印得下，一排長條有些有字有些沒有，
                     * 看起來會像資料缺了一塊。要精確數字就把滑鼠移上去。
                     */
                    var text = (config.barLabel && width >= LABEL_MIN_PCT)
                        ? '<span>' + App.esc(humanDuration(seconds)) + '</span>'
                        : '';

                    bars += '<i class="gantt-bar gantt-bar--' + App.esc(bar.code) + '"'
                          +   ' style="left:' + left + '%;width:' + width + '%'
                          +          ';background:' + App.esc(colorOf(bar.code)) + '"'
                          +   ' title="' + App.esc(tip) + '">' + text + '</i>';
                });

                /**
                 * 列標題可以點——開的是「這台機台」的詳細資料。
                 *
                 * 放在標題而不是放在區段上：詳細資料是機台層級的東西，
                 * 點哪一段都開同一個彈窗，那就不該讓人以為「點不同段會看到不同東西」。
                 * 區段本身仍然可以點（滑鼠順手），但不進 Tab 順序——
                 * 一列一個焦點就夠，八十台機台不需要一千多個 Tab 站點。
                 */
                var canDrill = config.drill && config.drill.api && row.key;

                var label = '<span class="gantt-row__name">' + App.esc(row.label || row.key) + '</span>'
                          + (row.group
                              ? '<span class="gantt-row__group">' + App.esc(row.group) + '</span>'
                              : '');

                var labelCell = canDrill
                    ? '<button type="button" class="gantt-row__label is-clickable"'
                      + ' data-key="' + App.esc(row.key) + '"'
                      + ' title="檢視 ' + App.esc(row.label || row.key) + ' 的詳細資料">'
                      + label + '</button>'
                    : '<div class="gantt-row__label">' + label + '</div>';

                html += '<div class="gantt-row"' + (canDrill ? ' data-key="' + App.esc(row.key) + '"' : '') + '>'
                      +   labelCell
                      +   '<div class="gantt-row__track">' + bars + '</div>'
                      + '</div>';
            });

            // 格線與「現在」線疊在所有列上面，一次畫完
            html += '<div class="gantt__overlay">'
                  +   '<div class="gantt__corner-spacer"></div>'
                  +   '<div class="gantt__overlay-track">' + lines + nowBar + '</div>'
                  + '</div>';

            html += '</div>';

            canvas.innerHTML = '<div class="gantt" style="'
                             + 'width:' + (zoom * 100) + '%;'
                             + '--gantt-label-w:' + (config.labelWidth || 132) + 'px;'
                             + '--gantt-row-h:' + (config.rowHeight || 22) + 'px;'
                             + '--gantt-row-gap:' + (config.rowGap || 6) + 'px'
                             + '">' + html + '</div>';
        }

        /**
         * 下方的統計列。
         *
         * 直接把後端算好的秒數畫出來，前端不重算——
         * 舊系統就是在前端各算各的，同一個稼動率在兩頁對不起來。
         */
        function renderSummary() {
            var box = wrap.querySelector('[data-role="gantt-summary"]');
            if (!box) return;

            var summary = data.summary || {};
            var total = Object.keys(summary).reduce(function (sum, key) {
                return sum + Number(summary[key] || 0);
            }, 0);

            if (total <= 0) {
                box.innerHTML = '';
                return;
            }

            box.innerHTML = Object.keys(config.legend).map(function (code) {
                var seconds = Number(summary[code] || 0);
                var pct = seconds * 100 / total;

                return '<div class="app-gantt__stat">' +
                       '<span class="app-gantt__swatch" style="background:' +
                            App.esc(config.legend[code].color) + '"></span>' +
                       '<span class="app-gantt__stat-label">' +
                            App.esc(config.legend[code].label) + '</span>' +
                       '<span class="app-gantt__stat-value">' +
                            App.esc(humanDuration(seconds)) + '</span>' +
                       '<span class="app-gantt__stat-pct">' + pct.toFixed(1) + '%</span>' +
                       '</div>';
            }).join('');
        }

        /**
         * 排程落後提示。
         *
         * 區段表是排程寫的，排程停掉的話圖會少掉最新那一段，
         * 但畫面上看起來就只是「機台最近沒動」——沒有提示的話沒人會發現。
         */
        function renderLag() {
            var box = wrap.querySelector('[data-role="gantt-lag"]');
            if (!box) return;

            /**
             * 資料被截斷。這件事比落後嚴重，所以優先顯示——
             * 圖上少了一截而畫面沒說的話，看的人會以為那些機台真的沒動。
             */
            if (data.truncated) {
                box.hidden = false;
                box.className = 'app-gantt__lag is-danger';
                box.innerHTML = '<i class="bi bi-exclamation-triangle"></i> 資料量超過 ' +
                                App.esc(String(data.max_bars || '')) +
                                ' 段，只畫出一部分，請縮小區間或指定機種';
                return;
            }

            var lag = Number(data.lag_minutes);

            if (isNaN(lag) || lag < 30) {
                box.hidden = true;
                return;
            }

            box.hidden = false;
            box.className = 'app-gantt__lag' + (lag >= 120 ? ' is-danger' : '');
            box.innerHTML = '<i class="bi bi-exclamation-triangle"></i> 資料落後 ' +
                            App.esc(humanDuration(lag * 60));
        }

        function load(params) {
            if (!config.api) return Promise.resolve();

            return App.http.get(config.api, params, { block: wrap })
                .then(function (result) {
                    data = result || {};
                    render();
                    renderSummary();
                    renderLag();
                    renderPaging();
                })
                .catch(function () { /* 訊息已由 App.http 處理 */ });
        }

        /**
         * 放大縮小。
         *
         * 只改圖的寬度——所有區段的 left / width 都是**百分比**，
         * 容器變寬它們自己就跟著變寬，不用重畫任何一條。
         * 視窗縮放同理，所以這一版沒有 resize 監聽。
         *
         * ★ 直接設 style.width，不要走 CSS 變數。
         *
         *   前一版是設 --gantt-zoom，再由 CSS 的
         *   `width: calc(100% * var(--gantt-zoom))` 換算。那條規則多踩了
         *   「calc 裡面套 var」這個組合，只要有一個環節不支援，整條 width
         *   就是無效值——**畫面完全不動，而且不會有任何錯誤**，
         *   倍率的數字還照樣跳，看起來就是「按了沒反應」。
         *
         *   寬度本來就是一個數字乘一個百分比，在 JS 算完直接寫上去，
         *   少一層轉譯也少一種壞法。
         */
        function setZoom(next) {
            zoom = Math.min(8, Math.max(1, next));

            var label = wrap.querySelector('[data-role="gantt-zoom-level"]');
            if (label) label.textContent = Math.round(zoom * 100) + '%';

            applyZoom();
        }

        /** 把目前倍率套到圖上。重畫之後也要再叫一次，不然會回到 100%。 */
        function applyZoom() {
            var chart = canvas.querySelector('.gantt');
            if (chart) chart.style.width = (zoom * 100) + '%';
        }

        /**
         * 分頁列。
         *
         * ★ 換頁是「整張圖重畫」，不是往下接。
         *
         *   甘特圖的列是一個個對象，第 2 頁是**另外一批**，跟第 1 頁沒有
         *   延續關係。接在後面的話列數會愈翻愈多，最後回到原本畫不完的問題。
         *
         * 沒有分頁資訊（指定單一機台）或只有一頁時整塊隱藏——
         * 一個按了不會有變化的按鈕比沒有還糟。
         */
        function renderPaging() {
            var box = wrap.querySelector('[data-role="gantt-paging"]');
            if (!box) return;

            var p = data.paging;

            if (!p || p.pages <= 1) {
                box.hidden = true;
                return;
            }

            box.hidden = false;

            var info = wrap.querySelector('[data-role="gantt-pageinfo"]');

            if (info) {
                /**
                 * 講「機台 1–15 / 共 87」而不是「第 1 / 6 頁」——
                 * 使用者關心的是看到哪幾列，不是頁碼。
                 *
                 * 名詞由 config.pageUnit 給：這個元件不綁領域，一列可能是
                 * 機台、排程單、人員。沒給就只顯示數字，照樣看得懂。
                 */
                var unit = config.pageUnit ? config.pageUnit + ' ' : '';

                info.textContent = unit + p.from + '–' + p.to + ' / 共 ' + p.total;
            }

            var prev = wrap.querySelector('[data-role="gantt-prev"]');
            var next = wrap.querySelector('[data-role="gantt-next"]');

            if (prev) prev.disabled = p.page <= 1;
            if (next) next.disabled = p.page >= p.pages;

            // 後端會把超出範圍的頁碼夾回來，這裡跟著同步，不然按上一頁會沒反應
            page = p.page;
        }

        /** 換頁：夾在有效範圍內再重查 */
        function goPage(next) {
            var p = data.paging;
            if (!p) return;

            var target = Math.min(Math.max(1, next), p.pages);

            if (target === page) return;

            page = target;
            load(currentParams());
        }

        // --- 工具列 ---
        bind('gantt-prev',    function () { goPage(page - 1); });
        bind('gantt-next',    function () { goPage(page + 1); });
        bind('gantt-zoom-in',  function () { setZoom(zoom * 1.5); });
        bind('gantt-zoom-out', function () { setZoom(zoom / 1.5); });
        bind('gantt-reset',    function () { setZoom(1); });
        bind('gantt-refresh',  function () { load(currentParams()); });

        /**
         * 點列標題或區段都開同一個彈窗。
         *
         * 用事件代理綁在容器上，不是每一條各綁一個——
         * 八十台機台上千條區段，各綁一個 handler 是白花的記憶體，
         * 而且每次重畫都要重綁。
         */
        canvas.addEventListener('click', function (event) {
            if (!config.drill || !config.drill.api) return;

            var row = event.target.closest('.gantt-row');
            if (!row || !row.dataset.key) return;

            var params = {};
            params[config.drill.param || 'id'] = row.dataset.key;

            App.modal.detail(config.drill.api, params);
        });

        /**
         * 要連動的下拉。用設定給的選擇器去找，不是寫死某個 id——
         * 一頁上有多張圖時，不該有一張圖偷偷去抓別人的篩選器。
         */
        var filterEl = config.filter ? document.querySelector(config.filter) : null;

        if (filterEl) {
            filterEl.addEventListener('change', function () { load(currentParams()); });
        }

        /** 固定參數 ＋ 連動下拉目前的值 ＋ 查詢條件列送來的參數 */
        var lastParams = {};

        /**
         * 目前在第幾頁機台。
         *
         * 跟 lastParams 分開放，因為兩者的生命週期不同：條件列每次按查詢
         * 都會換掉 lastParams，而那時候頁碼**必須回到第 1 頁**——
         * 換了機種還停在第 5 頁的話，畫面會是空的，看起來像查不到資料。
         */
        var page = 1;

        function currentParams() {
            var params = {};

            Object.keys(config.params || {}).forEach(function (key) {
                params[key] = config.params[key];
            });

            Object.keys(lastParams).forEach(function (key) {
                params[key] = lastParams[key];
            });

            params.page = page;

            if (filterEl && filterEl.value) {
                params[filterEl.name || 'type'] = filterEl.value;
            }

            return params;
        }

        /** 這張圖是不是掛在某個查詢條件列底下 */
        var ownedByFilter = hasFilterOwner(config.id);

        var instance = {
            id:     config.id,
            loaded: false,

            load: function (params) {
                instance.loaded = true;

                // 條件換了就回到第一頁（理由見 page 的宣告）
                if (params) {
                    lastParams = params;
                    page = 1;
                }

                return load(currentParams());
            },

            /**
             * 頁面載入時由查詢條件列把預設條件交過來。
             *
             * auto = true  的圖到這一刻才做第一次查詢（條件是齊的）
             * auto = false 的圖只記住條件，等使用者按查詢
             */
            prime: function (params) {
                if (params) {
                    lastParams = params;
                    page = 1;
                }

                if (config.auto !== false) {
                    instance.load();
                }
            },

            setZoom: setZoom
        };

        instances[config.id] = instance;

        /**
         * 後端直接把資料交過來的話就畫它，不打 API。
         *
         * 頁面已經在後端查好資料時少繞一次 HTTP；元件目錄那種靜態示範
         * 也走這條。之後按查詢仍然會打 api（沒給 api 就不會有反應，
         * 那本來就是一張靜態圖）。
         */
        if (config.data) {
            data = config.data;
            render();
            renderSummary();
            renderLag();

            return instance;
        }

        // 被條件列接管的圖，第一次查詢交給 prime()
        if (config.auto !== false && !ownedByFilter) {
            instance.load();
        }

        return instance;
    }

    function eachId(ids, fn) {
        String(ids || '').split(',').forEach(function (id) {
            id = id.trim();
            if (id) fn(id);
        });
    }

    App.gantt = {
        init: Gantt,

        get: function (id) { return instances[id] || null; },

        reload: function (id, params) {
            var instance = instances[id];
            if (instance) instance.load(params);
        },

        reloadAll: function (ids, params) {
            eachId(ids, function (id) {
                App.gantt.reload(id, params);
            });
        },

        /**
         * 頁面載入時把預設查詢條件交給圖。
         * 跟 reloadAll 的差別：auto = false 的圖只收下條件，不會被強制查詢。
         */
        primeAll: function (ids, params) {
            eachId(ids, function (id) {
                var instance = instances[id];
                if (instance) instance.prime(params);
            });
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(
            document.querySelectorAll('[data-gantt-config]'),
            Gantt
        );
    });

})(window.App);
