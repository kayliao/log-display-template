/**
 * 報表表格。
 *
 * 底層用 DataTables（Bootstrap 5 樣式），這一層負責把它包成
 * 「給一份欄位設定就能跑」的元件，頁面不需要碰 DataTables 的 API。
 *
 * 表頭（含大標小標、說明泡泡）已經由 PHP 的 table 元件渲染好，
 * DataTables 會自動辨識多列表頭，用最後一列對應欄位。
 *
 * 提供的能力：
 *   - 後端分頁（資料量大時不會把整張表撈回瀏覽器）
 *   - 排序（欄位白名單由後端控管，前端點了才有效）
 *   - 放大鏡欄位：點下去打 API，結果丟給 App.modal
 *   - 查詢條件變更時重新載入
 *   - CSV 匯出（帶著目前的查詢條件）
 *
 * 對外方法：
 *   App.table.get(id)                 取得實例
 *   App.table.reload(id, params)      帶新條件重新載入
 *   App.table.reloadAll(ids)          一次重載多張表
 */
(function (App) {
    'use strict';

    var instances = {};

    /**
     * 把欄位設定轉成 DataTables 的 columns。
     */
    function buildColumns(config) {
        return config.columns.map(function (col) {
            return {
                data: col.key,
                name: col.key,
                orderable: col.sortable !== false && !!col.key,
                visible: col.visible !== false,
                className: buildClass(col),
                defaultContent: '',

                render: function (value, type, row) {
                    // 排序與搜尋用原始值，只有顯示時才格式化
                    if (type !== 'display') return value;

                    var html = App.format.apply(col, value, row);

                    if (col.drill) {
                        // 放大鏡：把該列需要的參數包在 data 屬性上，點擊時再取出
                        var params = {};
                        (col.drill.params || [col.key]).forEach(function (p) {
                            params[p] = row[p];
                        });

                        html = '<a href="javascript:void(0)" class="app-drill" ' +
                               'data-drill-api="' + App.esc(col.drill.api) + '" ' +
                               "data-drill-params='" + App.esc(JSON.stringify(params)) + "'>" +
                               '<span>' + html + '</span>' +
                               '<i class="bi bi-search app-drill__icon"></i>' +
                               '</a>';
                    }

                    return html;
                }
            };
        });
    }

    function buildClass(col) {
        var cls = [];

        if (col.align)  cls.push('text-' + col.align);
        if (col.format === 'number' || col.format === 'decimal' ||
            col.format === 'percent' || col.align === 'right') {
            cls.push('app-td--number');
        }
        if (col.className) cls.push(col.className);

        return cls.join(' ');
    }

    /**
     * 找出初始排序欄位在 columns 中的位置。
     */
    function initialOrder(config) {
        if (!config.sort) return [];

        for (var i = 0; i < config.columns.length; i++) {
            if (config.columns[i].key === config.sort) {
                return [[i, config.dir === 'desc' ? 'desc' : 'asc']];
            }
        }

        return [];
    }

    function create(wrap) {
        var config = App.readConfig(wrap, 'data-table-config');
        if (!config) return null;

        var tableEl = document.getElementById(config.id);
        if (!tableEl) return null;

        var columns = buildColumns(config);

        var state = {
            params: {},                      // 目前的查詢條件

            /**
             * 是否可以真的去打 API。
             *
             * auto = false 的表格要等使用者按下查詢才載入。
             * 光是不呼叫 reload 沒有用——DataTables 在 serverSide 模式下
             * 初始化時就會自己送出第一次請求，那次請求沒有帶查詢條件，
             * 後端會因為缺少必填的日期區間而回錯誤，畫面上就會跳出紅色錯誤訊息。
             * 所以要在 ajax 這一層直接擋掉。
             */
            ready: config.auto !== false
        };

        var options = {
            columns: columns,
            order: initialOrder(config),
            paging: config.paging !== false,
            pageLength: config.size || 50,
            lengthMenu: [[25, 50, 100, 200], [25, 50, 100, 200]],
            searching: false,          // 搜尋由頁面的查詢條件列負責，不用 DataTables 內建的
            info: true,
            autoWidth: false,
            deferRender: true,
            scrollX: false,            // 水平捲動由外層 .app-table__scroll 處理
            language: zhTW(config),

            // 後端分頁：DataTables 只負責畫面，資料一律跟伺服器要
            serverSide: !!config.api && config.paging !== false,
            processing: false,         // 用自己的 loading 遮罩，樣式才一致

            ajax: config.api ? function (data, callback) {
                // 還沒按查詢：回空資料，不要打 API
                if (!state.ready) {
                    callback({ draw: data.draw, recordsTotal: 0, recordsFiltered: 0, data: [] });

                    return;
                }

                var params = Object.assign({}, state.params, {
                    page: Math.floor(data.start / data.length) + 1,
                    size: data.length
                });

                // 排序：把 DataTables 的欄位索引換回欄位名稱
                if (data.order && data.order.length) {
                    var col = columns[data.order[0].column];
                    if (col && col.data) {
                        params.sort = col.data;
                        params.dir  = data.order[0].dir;
                    }
                }

                App.http.get(config.api, params, { block: wrap })
                    .then(function (result) {
                        callback({
                            draw: data.draw,
                            recordsTotal: result.total,
                            recordsFiltered: result.total,
                            data: result.rows || []
                        });
                    })
                    .catch(function () {
                        // 查詢失敗時給空資料，避免表格卡在「載入中」
                        callback({ draw: data.draw, recordsTotal: 0, recordsFiltered: 0, data: [] });
                    });
            } : undefined,

            drawCallback: function () {
                App.initTooltips(wrap);
            }
        };

        var dt = jQuery('#' + config.id).DataTable(options);

        var instance = {
            id: config.id,
            config: config,
            dt: dt,
            wrap: wrap,
            loaded: config.auto !== false,

            /** 帶新條件重新載入 */
            reload: function (params, resetPage) {
                if (params) state.params = params;

                state.ready     = true;   // 從這一刻起才允許打 API
                instance.loaded = true;

                dt.ajax.reload(null, resetPage !== false);
            },

            /** 目前的查詢條件（匯出時要帶上） */
            params: function () {
                return state.params;
            }
        };

        instances[config.id] = instance;

        bindDrill(wrap);
        bindExport(wrap, instance);
        bindRefresh(wrap, instance);

        return instance;
    }

    /**
     * 放大鏡點擊。用事件委派綁在容器上，
     * 換頁重畫後不需要重新綁定。
     */
    function bindDrill(wrap) {
        wrap.addEventListener('click', function (e) {
            var link = e.target.closest('.app-drill');
            if (!link) return;

            e.preventDefault();

            var api = link.getAttribute('data-drill-api');
            var params;

            try {
                params = JSON.parse(link.getAttribute('data-drill-params') || '{}');
            } catch (err) {
                params = {};
            }

            App.modal.detail(api, params);
        });
    }

    /**
     * 匯出：把目前的查詢條件一起帶上，
     * 這樣匯出的內容跟畫面上看到的一定一致。
     */
    function bindExport(wrap, instance) {
        var btn = wrap.querySelector('[data-role="export"]');
        if (!btn) return;

        btn.addEventListener('click', function (e) {
            e.preventDefault();

            var params = Object.assign({}, instance.params(), { export: 'csv' });
            App.http.download(instance.config.api, params);
        });
    }

    function bindRefresh(wrap, instance) {
        var btn = wrap.querySelector('[data-role="refresh"]');
        if (!btn) return;

        btn.addEventListener('click', function () {
            instance.reload(null, false);
        });
    }

    /** DataTables 的中文介面文字 */
    function zhTW(config) {
        return {
            emptyTable: config.empty || '沒有符合條件的資料',
            zeroRecords: config.empty || '沒有符合條件的資料',
            info: '第 _START_ – _END_ 筆，共 _TOTAL_ 筆',
            infoEmpty: '共 0 筆',
            infoFiltered: '',
            lengthMenu: '每頁 _MENU_ 筆',
            loadingRecords: '載入中…',
            processing: '處理中…',
            paginate: {
                first: '第一頁',
                last: '最後一頁',
                next: '下一頁',
                previous: '上一頁'
            }
        };
    }

    App.table = {
        get: function (id) {
            return instances[id] || null;
        },

        reload: function (id, params, resetPage) {
            var instance = instances[id];
            if (instance) instance.reload(params, resetPage);
        },

        /** 一次重載多張表（例如同一組查詢條件對應明細與統計兩張表） */
        reloadAll: function (ids, params) {
            (Array.isArray(ids) ? ids : String(ids).split(','))
                .map(function (s) { return s.trim(); })
                .filter(Boolean)
                .forEach(function (id) {
                    App.table.reload(id, params);
                });
        },

        /** 手動初始化（動態插入的表格用） */
        init: function (wrap) {
            return create(wrap);
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(
            document.querySelectorAll('[data-table-config]'),
            create
        );
    });

})(window.App);
