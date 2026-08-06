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
 *   { machines: [ {id, model, x, y, w, h, status, status_label}, ... ],
 *     summary:  { RUN: 12, IDLE: 3, ... } }
 */
(function (App) {
    'use strict';

    var SVG_NS = 'http://www.w3.org/2000/svg';

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

        var AXIS = 34;   // 座標軸佔的寬度
        var cell = config.cell || 88;
        var gap  = config.gap || 6;

        /** 橫軸代號（A、B、C…）換算成第幾欄 */
        function colIndex(x) {
            var i = config.axisX.indexOf(String(x).toUpperCase());
            return i >= 0 ? i : -1;
        }

        /** 縱軸號碼換算成第幾列 */
        function rowIndex(y) {
            var i = config.axisY.indexOf(Number(y));
            if (i >= 0) return i;
            // axisY 可能是字串陣列，再比對一次
            return config.axisY.map(String).indexOf(String(y));
        }

        function statusColor(status) {
            var meta = config.legend[String(status).toUpperCase()];
            return meta ? meta.color : 'var(--status-off)';
        }

        function render() {
            var cols = config.axisX.length;
            var rows = config.axisY.length;

            var width  = AXIS + cols * cell;
            var height = AXIS + rows * cell;

            var svg = el('svg', {
                width: width * zoom,
                height: height * zoom,
                viewBox: '0 0 ' + width + ' ' + height
            });

            // --- 座標軸標籤 ---
            config.axisX.forEach(function (label, i) {
                var t = el('text', {
                    x: AXIS + i * cell + cell / 2,
                    y: AXIS - 12,
                    'text-anchor': 'middle',
                    class: 'map-axis'
                });
                t.textContent = label;
                svg.appendChild(t);
            });

            config.axisY.forEach(function (label, i) {
                var t = el('text', {
                    x: AXIS - 12,
                    y: AXIS + i * cell + cell / 2 + 4,
                    'text-anchor': 'end',
                    class: 'map-axis'
                });
                t.textContent = label;
                svg.appendChild(t);
            });

            // --- 格線 ---
            for (var c = 0; c <= cols; c++) {
                svg.appendChild(el('line', {
                    x1: AXIS + c * cell, y1: AXIS,
                    x2: AXIS + c * cell, y2: height,
                    class: 'map-grid'
                }));
            }
            for (var r = 0; r <= rows; r++) {
                svg.appendChild(el('line', {
                    x1: AXIS, y1: AXIS + r * cell,
                    x2: width, y2: AXIS + r * cell,
                    class: 'map-grid'
                }));
            }

            // --- 機台 ---
            data.machines.forEach(function (m) {
                var ci = colIndex(m.x);
                var ri = rowIndex(m.y);

                if (ci < 0 || ri < 0) {
                    // 座標超出設定的軸範圍，跳過但留紀錄方便現場對資料
                    console.warn('機台座標不在平面圖範圍內：', m.id, m.x, m.y);
                    return;
                }

                var w = Math.max(1, m.w || 1);
                var h = Math.max(1, m.h || 1);

                var g = el('g', {
                    class: 'map-machine map-machine--' + String(m.status).toLowerCase()
                });

                g.appendChild(el('rect', {
                    x: AXIS + ci * cell + gap / 2,
                    y: AXIS + ri * cell + gap / 2,
                    width:  w * cell - gap,
                    height: h * cell - gap,
                    fill: statusColor(m.status),
                    class: 'map-machine__box'
                }));

                var cx = AXIS + ci * cell + (w * cell) / 2;
                var cy = AXIS + ri * cell + (h * cell) / 2;

                var idText = el('text', {
                    x: cx, y: cy - 2, 'text-anchor': 'middle', class: 'map-machine__id'
                });
                idText.textContent = m.id;
                g.appendChild(idText);

                if (m.model) {
                    var modelText = el('text', {
                        x: cx, y: cy + 14, 'text-anchor': 'middle', class: 'map-machine__model'
                    });
                    modelText.textContent = m.model;
                    g.appendChild(modelText);
                }

                // 滑鼠停留顯示完整資訊
                var title = el('title', {});
                title.textContent = m.id + ' / ' + (m.model || '') +
                                    '\n狀態：' + (m.status_label || m.status) +
                                    '\n位置：' + m.x + m.y +
                                    (m.last_report_time ? '\n最後回報：' + m.last_report_time : '');
                g.appendChild(title);

                // 點擊開啟機台詳細資料（跟表格放大鏡是同一個彈窗與同一支 API）
                g.addEventListener('click', function () {
                    App.modal.detail(App.url('/api/machine/detail.php'), { machine_id: m.id });
                });

                svg.appendChild(g);
            });

            canvas.innerHTML = '';
            canvas.appendChild(svg);
        }

        function renderSummary() {
            var box = document.querySelector('[data-role="map-summary"]');
            if (!box) return;

            box.innerHTML = Object.keys(config.legend).map(function (status) {
                var meta = config.legend[status];
                var count = data.summary[status] || 0;

                return '<div class="app-stat">' +
                       '<span class="app-stat__dot" style="background:' + App.esc(meta.color) + '"></span>' +
                       '<span class="app-stat__label">' + App.esc(meta.label) + '</span>' +
                       '<span class="app-stat__value">' + count + '</span>' +
                       '</div>';
            }).join('');
        }

        function load(params) {
            if (!config.api) return Promise.resolve();

            return App.http.get(config.api, params, { block: wrap })
                .then(function (result) {
                    data = result;
                    render();
                    renderSummary();
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

        // 廠區下拉切換
        var areaSelect = document.getElementById('f_map_area');
        if (areaSelect) {
            areaSelect.addEventListener('change', function () { load(currentParams()); });
        }

        function currentParams() {
            return areaSelect && areaSelect.value ? { area: areaSelect.value } : {};
        }

        load(currentParams());

        return { load: load, setZoom: setZoom };
    }

    App.machineMap = { init: MachineMap };

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(
            document.querySelectorAll('[data-map-config]'),
            MachineMap
        );
    });

})(window.App);
