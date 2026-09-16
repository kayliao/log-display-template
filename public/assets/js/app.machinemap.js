/**
 * 廠內機台平面圖。
 *
 * 用原生 SVG 畫，沒有引入繪圖套件——
 * 需求是「格線座標 + 帶文字的矩形」，自己畫大約兩百行，
 * 比引入一個要離線佈署又要學 API 的套件划算。
 *
 * 座標系統：
 *   橫軸 A、B、C…（對應 config 的 axisX 陣列位置）
 *   縱軸 1、2、3…（對應 axisY）
 *   機台用 x/y 定位左上角，w/h 表示佔幾格（就是機台長寬）
 *
 * API 回傳：
 *   { machines: [ {machine_id, model, x, y, w, h, status, status_label}, ... ],
 *     summary:  { RUN: 12, IDLE: 3, ... } }
 *
 * 欄位名沿用資料庫的 machine_id，跟表格、放大鏡、詳細資料 API 一致，
 * 不要在這裡另外取別名，否則哪天改欄位會漏掉這一支。
 */
window.App = window.App || {};

(function (App) {
    'use strict';

    // 同一支檔案被載入兩次時，第二次直接跳出（原因見 app.core.js 開頭）
    App.__loaded = App.__loaded || {};
    if (App.__loaded.machinemap) return;
    App.__loaded.machinemap = true;

    /**
     * SVG 的 XML 命名空間。
     *
     * ⚠ 這**不是**一個會被下載的網址，是 `createElementNS()` 用來辨識
     *   「這個元素屬於 SVG 而不是 HTML」的固定識別字。離線環境完全正常，
     *   瀏覽器從頭到尾不會對它發出任何請求。
     *
     *   ★ 不要因為「這台機器沒有網路」就把它拿掉或改成空字串——
     *     那樣產生的會是沒有命名空間的元素，**畫面直接空白而且不報錯**，
     *     比留著難查得多。
     */
    var SVG_NS = 'http://www.w3.org/2000/svg';

    /** id => 實例。分頁籤要叫某一張圖去載入時用得到。 */
    var instances = {};

    function el(name, attrs) {
        var node = document.createElementNS(SVG_NS, name);
        Object.keys(attrs || {}).forEach(function (k) {
            node.setAttribute(k, attrs[k]);
        });
        return node;
    }

    function MachineMap(wrap) {
        var config = App.readConfig(wrap, 'data-map-config');
        if (!config) return null;

        var canvas = wrap.querySelector('[data-role="map-canvas"]');
        var zoom   = 1;
        var data   = { machines: [], summary: {} };

        /** 自動更新用的計時器與旗標（沒設定 refresh 就整組用不到） */
        var timer          = null;
        var paused         = false;
        var autoRefreshing = false;   // 這一次是自動更新（不蓋遮罩）還是使用者按的

        /**
         * 資訊快照目前顯示哪一個欄位。
         * 空字串 = 顯示預設的 label（機台編號）。
         */
        var snapshotField = '';

        /**
         * 一格多寬、多高。
         *
         * ★ 欄寬與列高是**分開的兩個數字**。
         *
         *   原本只有一個 cell，橫豎都用它，格子永遠是正方形——
         *   但廠房不是正方形的：機台一排一排擺，橫向要放得下編號與機種兩行字，
         *   縱向只是「第幾排」，用不著跟寬度一樣高。列高等於欄寬的結果是
         *   整張圖被拉得很長，一個螢幕看不完，得一直往下捲。
         *
         *   cellY 不給就等於 cellX，跟以前完全一樣（另外兩頁平面圖走這條）。
         */
        var cellX = config.cell || 88;
        var cellY = config.cellY || cellX;
        var gap   = config.gap || 6;

        /**
         * 座標軸的留白。左右與上下拆開，因為它們裝的東西不一樣：
         *
         *   AXIS_L  左邊那排「列號」的寬度。內容是 1~2 位數字，很窄就夠。
         *   AXIS_T  上面那排「欄號」的高度。那是文字的高度，不能再壓。
         *
         * 原本兩個共用一個 34，結果左邊白白吃掉一大塊 ——
         * 格子縮小之後更明顯，整張圖看起來偏右邊一大截。
         * 各自跟著自己那個方向的格子大小走。
         */
        var AXIS_L = Math.max(16, Math.round(cellX * 0.23));   // 88 -> 20
        var AXIS_T = Math.max(16, Math.round(cellY * 0.25));   // 88 -> 22

        /** 橫軸代號（A、B、C…）換算成第幾欄 */
        function colIndex(x) {
            var i = axisX().indexOf(String(x).toUpperCase());
            return i >= 0 ? i : -1;
        }

        /** 縱軸號碼換算成第幾列 */
        function rowIndex(y) {
            var ay = axisY();
            var i  = ay.indexOf(Number(y));
            if (i >= 0) return i;
            // axisY 可能是字串陣列，再比對一次
            return ay.map(String).indexOf(String(y));
        }

        function statusColor(status) {
            var meta = config.legend[String(status).toUpperCase()];
            return meta ? meta.color : 'var(--status-off)';
        }

        /**
         * 這一格的底色是不是淺的（淺底要配深字）。
         *
         * ★ 不要用狀態代碼去列舉（--3、--4、--5…）。
         *   現場多一種狀態就要回來改一次 CSS，而且漏掉的症狀是
         *   「那幾台的字不見了」—— 不報錯，只有現場會發現。
         *
         *   改成問圖例：哪個底色是淺的，由給圖例的人標 dark: true。
         *   對不到圖例的（固定裝置、沒列的代碼）一律算淺底 ——
         *   它們吃的是 --status-off（#cbd5e1），本來就很淺。
         */
        function needsDarkText(status) {
            var meta = config.legend[String(status).toUpperCase()];
            return meta ? !!meta.dark : true;
        }

        /**
         * 軸標籤優先用 API 送來的。
         *
         * 後端從版面表算出實際用到的最大範圍（見 MachineService::mapData），
         * 所以新增一台放在更右邊或更下面的機器不用改任何程式。
         * API 沒送才退回元件設定的預設值。
         */
        function axisX() { return (data.axis_x && data.axis_x.length) ? data.axis_x : config.axisX; }
        function axisY() { return (data.axis_y && data.axis_y.length) ? data.axis_y : config.axisY; }

        function render() {
            var ax   = axisX();
            var ay   = axisY();
            var cols = ax.length;
            var rows = ay.length;

            var width  = AXIS_L + cols * cellX;
            var height = AXIS_T + rows * cellY;

            var svg = el('svg', {
                width: width * zoom,
                height: height * zoom,
                viewBox: '0 0 ' + width + ' ' + height
            });

            // --- 座標軸標籤 ---
            ax.forEach(function (label, i) {
                var t = el('text', {
                    x: AXIS_L + i * cellX + cellX / 2,
                    y: AXIS_T - 6,
                    'text-anchor': 'middle',
                    class: 'map-axis'
                });
                t.textContent = label;
                svg.appendChild(t);
            });

            ay.forEach(function (label, i) {
                var t = el('text', {
                    x: AXIS_L - 5,
                    y: AXIS_T + i * cellY + cellY / 2 + 4,
                    'text-anchor': 'end',
                    class: 'map-axis'
                });
                t.textContent = label;
                svg.appendChild(t);
            });

            // --- 格線 ---
            for (var c = 0; c <= cols; c++) {
                svg.appendChild(el('line', {
                    x1: AXIS_L + c * cellX, y1: AXIS_T,
                    x2: AXIS_L + c * cellX, y2: height,
                    class: 'map-grid'
                }));
            }
            for (var r = 0; r <= rows; r++) {
                svg.appendChild(el('line', {
                    x1: AXIS_L, y1: AXIS_T + r * cellY,
                    x2: width,  y2: AXIS_T + r * cellY,
                    class: 'map-grid'
                }));
            }

            // --- 機台 ---
            data.machines.forEach(function (m) {
                var id = m.machine_id;

                /**
                 * 畫在格子裡的字。
                 *
                 * label 是現場叫的機台編號（或固定裝置的名稱），machine_id 是
                 * 電腦名稱——點下去查詳細資料要用後者，畫面上要看前者，
                 * 所以兩個都送、各司其職。
                 */
                var label = m.label || id;

                /**
                 * 資訊快照選了欄位就顯示那個欄位。
                 *
                 * 固定裝置沒有這些欄位（它們不上拋），維持顯示原本的名稱——
                 * 換成空白的話，切到「電腦 IP」時整片參照物會消失，
                 * 看的人會以為圖壞了。
                 */
                if (snapshotField && m[snapshotField]) {
                    label = m[snapshotField];
                }

                var ci = colIndex(m.x);
                var ri = rowIndex(m.y);

                if (ci < 0 || ri < 0) {
                    // 座標超出軸的範圍，跳過但留紀錄方便現場對資料
                    console.warn('機台座標不在平面圖範圍內：', label, m.x, m.y);
                    return;
                }

                var w = Math.max(1, m.w || 1);
                var h = Math.max(1, m.h || 1);

                var g = el('g', {
                    class: 'map-machine map-machine--' + String(m.status).toLowerCase() +
                           (needsDarkText(m.status) ? ' is-dark-text' : '')
                });

                g.appendChild(el('rect', {
                    x: AXIS_L + ci * cellX + gap / 2,
                    y: AXIS_T + ri * cellY + gap / 2,
                    width:  w * cellX - gap,
                    height: h * cellY - gap,
                    fill: statusColor(m.status),
                    class: 'map-machine__box'
                }));

                var cx = AXIS_L + ci * cellX + (w * cellX) / 2;
                var cy = AXIS_T + ri * cellY + (h * cellY) / 2;

                /**
                 * 兩行字的字級與行距跟著格子大小走。
                 *
                 * ★ 原本 12px / 10.5px 與 y 的 -2 / +14 都是寫死的，那是照
                 *   cell = 88 量出來的。格子調小之後字不會跟著縮，格子會被字
                 *   撐滿；再小一點第二行的 +14 更會直接掉到格子外面，
                 *   跟下一列的機台疊在一起 —— 看起來就是「破版」。
                 *
                 * ★ 基準取「欄寬」與「這台佔的總高」兩者的小的那個。
                 *
                 *   列高壓扁之後，限制字級的是**高度**不是寬度，所以要看
                 *   h * cellY（這台佔兩列就是兩列的高度），不是單一格的高度——
                 *   不然佔兩列的機台明明放得下卻被縮成最小字。
                 *   另一邊用 cellX 而不是 w * cellX：字級是給一台機器用的，
                 *   不該因為某台特別寬就把字放大一倍。
                 *
                 *   兩邊都是 88 時算出來就是 12 / 10.5，跟改動前完全一樣，
                 *   只有把格子調小才會生效。下限 8px 是現場螢幕還讀得出來的底線。
                 */
                var box       = Math.min(cellX, h * cellY);
                var idSize    = Math.max(8, Math.round(box * 0.136));   // 88 -> 12
                var modelSize = Math.max(8, Math.round(box * 0.119));   // 88 -> 10.5
                var lineGap   = Math.round(idSize * 1.25);

                var hasModel = !!m.model;

                var idText = el('text', {
                    x: cx,
                    // 只有一行字時置中，有兩行才往上讓出第二行的位置
                    y: hasModel ? cy - 2 : cy + idSize / 3,
                    'text-anchor': 'middle',

                    /**
                     * ⚠ 字級要寫成 **style**，不能寫成 font-size 屬性。
                     *
                     *   SVG 的 presentation attribute 優先權是 0，**任何一條 CSS
                     *   規則都壓得過它**——app.css 裡的 `.map-machine__id
                     *   { font-size: 12px }` 就會把它蓋掉，字級永遠是 12px，
                     *   格子調小也不會跟著縮。而且畫面上看不出「被蓋掉」，
                     *   只會覺得「這段程式沒作用」。寫成 style 就贏得過類別規則。
                     */
                    style: 'font-size:' + idSize + 'px',
                    class: 'map-machine__id'
                });
                idText.textContent = label;
                g.appendChild(idText);

                if (hasModel) {
                    var modelText = el('text', {
                        x: cx, y: cy - 2 + lineGap, 'text-anchor': 'middle',
                        style: 'font-size:' + modelSize + 'px',   // 理由同上
                        class: 'map-machine__model'
                    });
                    modelText.textContent = m.model;
                    g.appendChild(modelText);
                }

                // 滑鼠停留顯示完整資訊
                var title = el('title', {});
                title.textContent = tipText(m, label);
                g.appendChild(title);

                /**
                 * 點擊開啟詳細資料（跟表格放大鏡是同一個彈窗）。
                 *
                 * 沒有 machine_id 的是固定裝置（烘箱、貼標機、走道標示）——
                 * 它們有位置但不上拋，點了也沒有東西可以看，所以不掛事件、
                 * 游標也不會變成手指，不要讓人以為點得到。
                 *
                 * ★ 有些機台點下去要看的是別的東西（水化機看的是槽況）。
                 *   哪一台走哪一支 API 由**後端**在資料裡標記（m.tank），
                 *   前端不要自己判斷機種代碼——機種的意義是後端的事。
                 */
                if (id) {
                    g.classList.add('is-clickable');

                    g.addEventListener('click', function () {
                        var api = (m.tank && config.tankApi) ? config.tankApi : config.detailApi;

                        if (api) {
                            App.modal.detail(App.url(api), { machine_id: id });
                        }
                    });
                }

                svg.appendChild(g);
            });

            canvas.innerHTML = '';
            canvas.appendChild(svg);
        }

        /**
         * 格子上的滑鼠提示。
         *
         * 後端有送 tip（一列一個欄位）就照它畫——**要顯示什麼是後端決定的**，
         * 因為那牽涉到「這個機種在這個狀態下該不該顯示這個數字」這種業務規則，
         * 寫在前端的話每加一個機種就要改 JS（舊系統就是這樣，
         * 十幾層三元運算子串成一個字串）。
         *
         * 沒送 tip 就用通用的四行，另外兩頁平面圖走的是這條。
         */
        function tipText(m, label) {
            if (m.tip && m.tip.length) {
                return m.tip.map(function (row) {
                    return row[0] + '：' + row[1];
                }).join('\n');
            }

            return label + ' / ' + (m.model || '') +
                   '\n狀態：' + (m.status_label || m.status) +
                   '\n位置：' + m.x + m.y +
                   (m.last_report_time ? '\n最後回報：' + m.last_report_time : '');
        }

        /**
         * 狀態統計。
         *
         * ⚠ 容器是用 `document` 找的，不是 `wrap`——統計那一塊在頁面的左欄，
         *   不在圖裡面，而且**一頁上的每張圖共用同一塊**（樓層分頁籤有兩張圖）。
         *
         *   所以送過來的統計必須是**整廠**的，不能是各自那一層的：
         *   兩張圖都在自動更新，各送各的樓層數字的話，這一塊會每十秒被另一張圖
         *   蓋掉一次，畫面上的稼動數就會在兩個數字之間跳。
         *   後端送的統計要算整廠（Service 那一層算好再送），
         *   兩張圖送回來的數字一樣，誰先到都無所謂。
         */
        function renderSummary() {
            var box = document.querySelector('[data-role="map-summary"]');
            if (!box) return;

            var summary = data.summary || {};

            /**
             * 統計的兩種形狀都要吃得下：
             *   { "0": 12 }                                   只有台數
             *   { "0": {label:"稼動", count:12, ratio:46.9} }  台數加比率
             * 後者是即時機況地圖用的——比率在後端算好，
             * 才不會每一頁各算各的（舊系統的比率分母還會邊畫邊變）。
             */
            /**
             * 先照圖例的順序，後面接上**圖例沒有的**狀態。
             *
             * ★ 只畫圖例列的那幾種是不夠的。現況表如果出現第八種代碼，
             *   那幾台會被算進分母卻不屬於任何一列——畫面上的數字加起來
             *   就比機台總數少，百分比也湊不到 100%，而且不報錯。
             *   後端會把它們收成一列（見 summaryWithRatio 的「其他」），
             *   這裡負責讓那一列畫得出來。
             */
            var codes = Object.keys(config.legend);

            Object.keys(summary).forEach(function (code) {
                if (codes.indexOf(code) < 0) codes.push(code);
            });

            box.innerHTML = codes.map(function (status) {
                var meta  = config.legend[status];
                var value = summary[status];
                var count = (value && typeof value === 'object') ? (value.count || 0) : (value || 0);
                var ratio = (value && typeof value === 'object' && value.ratio !== undefined)
                    ? '<span class="app-stat__hint">' + App.esc(value.ratio) + '%</span>'
                    : '';

                // 圖例沒有的：文字用後端送的，顏色用「未知」那一色
                var label = meta ? meta.label
                    : ((value && typeof value === 'object' && value.label) || ('狀態 ' + status));
                var color = meta ? meta.color : 'var(--status-off)';

                return '<div class="app-stat">' +
                       '<span class="app-stat__dot" style="background:' + App.esc(color) + '"></span>' +
                       '<span class="app-stat__label">' + App.esc(label) + '</span>' +
                       '<span class="app-stat__value">' + count + '</span>' + ratio +
                       '</div>';
            }).join('');
        }

        /**
         * 機型統計（每一種機台幾台、其中幾台有連線）。
         *
         * 頁面上沒有放這個容器就不畫——這是即時機況地圖才有的東西，
         * 另外兩頁平面圖不需要，也不該為了它多打一次 API。
         */
        function renderTypes() {
            var box = document.querySelector('[data-role="map-types"]');
            if (!box || !data.types) return;

            var rows = data.types.map(function (t) {
                /**
                 * 整種都不上拋的設備（裝盒機、貼標機這類）連線數顯示「—」。
                 *
                 * 它們不進機台現況表，本來就不會有連線數。顯示成紅色的 0
                 * 等於每天都在報一個不會好的警訊，真正掉線的機台反而被淹掉。
                 */
                if (!t.reports) {
                    return '<tr><td>' + App.esc(t.label) + '</td>' +
                           '<td class="is-muted" title="這種機台不上拋，沒有連線狀態">—</td>' +
                           '<td>' + t.placed + '</td>' +
                           '<td class="is-muted">—</td></tr>';
                }

                // 連線數少於廠區圖台數就標紅，一眼看得出哪一種機台掉線了
                var off = t.online < t.placed ? ' class="is-warn"' : '';

                // 現況表有、廠區圖上沒有的，也標出來——那幾台在畫面上根本看不到
                var missing = t.total > t.placed ? ' class="is-warn"' : '';

                return '<tr><td>' + App.esc(t.label) + '</td>' +
                       '<td' + off + '>' + t.online + '</td>' +
                       '<td>' + t.placed + '</td>' +
                       '<td' + missing + '>' + t.total + '</td></tr>';
            }).join('');

            function sum(key) {
                return data.types.reduce(function (n, t) { return n + (t[key] || 0); }, 0);
            }

            var total   = sum('total');
            var placed  = sum('placed');
            var missing = total - placed;

            /**
             * 「廠區圖」與「現況表」是兩個不同的來源：
             *   廠區圖（版面表）是現場有哪些機台——每一台都在上面
             *   現況表（機台現況）只有會上拋的那些
             *
             * 所以現況表比廠區圖少是正常的（差的就是不上拋的設備）；
             * **反過來多出來才是問題**：那是有機台還沒登記位置，
             * 或報廢之後沒清掉，畫面上看不到它。差多少就寫出來。
             */
            var note = missing > 0
                ? '<p class="app-panel__hint app-minitable__note">' +
                  '現況表有 <strong>' + missing + '</strong> 台不在廠區圖上，' +
                  '這幾台不會出現在圖上。' +
                  '</p>'
                : '';

            box.innerHTML =
                '<table class="app-minitable">' +
                '<thead><tr><th>機台別</th><th>連線</th><th>廠區圖</th><th>現況表</th></tr></thead>' +
                '<tbody>' + rows + '</tbody>' +
                '<tfoot><tr><th>合計</th><th>' + sum('online') + '</th>' +
                '<th>' + placed + '</th><th>' + total + '</th></tr></tfoot>' +
                '</table>' + note;
        }

        /** 這一份資料是什麼時候的（後端送的伺服器時間，不是瀏覽器時間） */
        function renderRefreshTime() {
            var box = wrap.querySelector('[data-role="map-refresh-time"]');

            if (box && data.refresh_time) {
                box.textContent = data.refresh_time;
            }
        }

        function load(params) {
            if (!config.api) return Promise.resolve();

            /**
             * ★ 自動更新時不要蓋遮罩。
             *
             *   每幾秒閃一次白幕，看板就沒辦法看了；而且遮罩期間滑鼠移到格子上
             *   會沒有反應。第一次載入與手動重新整理才擋。
             *
             * ⚠ 這裡一定要寫 `loading: false`，不能只給空物件——
             *   App.http 的判斷是 `options.loading !== false && !options.block`，
             *   空物件兩個條件都成立，全螢幕遮罩照樣會蓋下來。
             */
            var options = autoRefreshing ? { loading: false } : { block: wrap };

            return App.http.get(config.api, params, options)
                .then(function (result) {
                    data = result;
                    render();
                    renderSummary();
                    renderTypes();
                    renderRefreshTime();
                })
                .catch(function () { /* 訊息已由 App.http 處理 */ });
        }

        function setZoom(next) {
            zoom = Math.min(2, Math.max(0.5, next));

            var label = wrap.querySelector('[data-role="map-zoom-level"]');
            if (label) label.textContent = Math.round(zoom * 100) + '%';

            render();
        }

        // --- 工具列 ---
        wrap.querySelector('[data-role="map-zoom-in"]')
            .addEventListener('click', function () { setZoom(zoom + 0.1); });

        wrap.querySelector('[data-role="map-zoom-out"]')
            .addEventListener('click', function () { setZoom(zoom - 0.1); });

        wrap.querySelector('[data-role="map-reset"]')
            .addEventListener('click', function () { setZoom(1); });

        wrap.querySelector('[data-role="map-refresh"]')
            .addEventListener('click', function () { load(currentParams()); });

        /**
         * 資訊快照：把格子裡的字換成別的欄位（電腦 IP、各種版本）。
         *
         * ★ 不重打 API。快照本來就是「同一份資料換個欄位看」——
         *   舊版是把整張表格重畫成另一張表格放在旁邊，兩張表的資料
         *   還可能是不同時間點的。這裡就是同一份資料換一個欄位。
         */
        var snapshotEl = wrap.querySelector('[data-role="map-snapshot"]');

        if (snapshotEl) {
            snapshotEl.addEventListener('change', function () {
                snapshotField = snapshotEl.value;
                render();
            });
        }

        /**
         * 自動更新。
         *
         * ★ 舊版叫「long polling」但其實是 `async: false` 的定時重查——
         *   同步 XHR 會**凍住整個瀏覽器分頁**，而且它是在 complete 裡再排下一次，
         *   所以查詢愈慢、畫面卡得愈久。這裡用一般的非同步查詢加計時器。
         *
         * ★ 可以暫停。要抄畫面上的批號、或要把滑鼠停在某一格看提示的時候，
         *   每幾秒重畫一次是沒辦法用的。舊版沒有這個開關。
         */
        var pauseEl = wrap.querySelector('[data-role="map-pause"]');

        function scheduleNext() {
            clearTimeout(timer);

            if (!config.refresh || paused) return;

            timer = setTimeout(function () {
                autoRefreshing = true;

                load(currentParams()).then(function () {
                    autoRefreshing = false;
                    scheduleNext();
                });
            }, config.refresh * 1000);
        }

        if (pauseEl) {
            pauseEl.addEventListener('click', function () {
                paused = !paused;

                pauseEl.classList.toggle('active', paused);
                pauseEl.innerHTML = paused
                    ? '<i class="bi bi-play-fill"></i> 已暫停'
                    : '<i class="bi bi-pause-fill"></i> 暫停更新';

                if (!paused) {
                    load(currentParams()).then(scheduleNext);
                } else {
                    clearTimeout(timer);
                }
            });
        }

        /**
         * 分頁籤藏起來的那張圖不要繼續更新。
         *
         * 兩張圖都在 DOM 裡（Bootstrap 只是把非目前頁籤隱藏），
         * 不管的話看不見的那一張也在每幾秒打一次 API。
         */
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                clearTimeout(timer);
            } else if (!paused) {
                scheduleNext();
            }
        });

        /**
         * 要連動的下拉選單。
         *
         * 用設定給的選擇器去找，不是寫死某個 id——
         * 一頁上有多張圖時（例如分頁版的 2F / 4F），
         * 不該有一張圖偷偷去抓別人的篩選器。
         */
        var filterEl = config.filter ? document.querySelector(config.filter) : null;

        if (filterEl) {
            filterEl.addEventListener('change', function () { load(currentParams()); });
        }

        /** 固定參數（例如樓層）＋ 連動下拉目前的值 */
        function currentParams() {
            var params = {};

            Object.keys(config.params || {}).forEach(function (key) {
                params[key] = config.params[key];
            });

            if (filterEl && filterEl.value) {
                params[filterEl.name || 'area'] = filterEl.value;
            }

            return params;
        }

        var instance = {
            id:      config.id,
            loaded:  false,

            /**
             * 載入一次，然後排下一次自動更新。
             *
             * 分頁籤是切過去才第一次載入（auto = false），
             * 所以自動更新的計時器也要等到那時候才開始排——
             * 否則看不見的那張圖也在背景每幾秒打一次 API。
             */
            load: function () {
                instance.loaded = true;

                return load(currentParams()).then(function (result) {
                    scheduleNext();

                    return result;
                });
            },

            setZoom: setZoom
        };

        instances[config.id] = instance;

        if (config.auto !== false) {
            instance.load();
        }

        return instance;
    }

    App.machineMap = {
        init: MachineMap,
        get: function (id) { return instances[id] || null; }
    };

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(
            document.querySelectorAll('[data-map-config]'),
            MachineMap
        );
    });

})(window.App);
