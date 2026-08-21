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
 *   - 勾選（設了 select 才有；換頁、排序、重查都不會掉）
 *
 * 對外方法：
 *   App.table.get(id)                 取得實例
 *   App.table.reload(id, params)      帶新條件重新載入
 *   App.table.reloadAll(ids)          一次重載多張表
 *   App.table.adjustAll(ids)          重算欄寬（容器寬度變了才需要）
 *   App.table.selected(id)            勾起來的識別碼
 *   App.table.setSelected(id, ids)    直接指定勾選哪些
 *   App.table.clearSelection(ids)     清掉勾選
 *   App.table.selectAllMatching(id)   全選這次查到的全部，回傳 Promise
 *
 * 勾選變動時容器會冒泡一個 app:table:select 事件，
 * detail 是 { id, selected }，頁面要跟著更新別的東西時聽它。
 */
window.App = window.App || {};

(function (App) {
    'use strict';

    // 同一支檔案被載入兩次時，第二次直接跳出（原因見 app.core.js 開頭）
    App.__loaded = App.__loaded || {};
    if (App.__loaded.table) return;
    App.__loaded.table = true;

    var instances = {};

    /**
     * 勾選欄。
     *
     * 存的是識別碼（data-id）而不是畫面上那個 checkbox 元素，
     * 所以換頁、重新排序、重新查詢之後勾選都還在 —— DataTables 會把
     * 舊的 <tr> 整個丟掉重畫，記元素是記不住的。
     */
    function selectColumn(config) {
        return {
            data: null,
            orderable: false,
            searchable: false,
            className: 'app-td--select',
            defaultContent: '',

            render: function (value, type, row) {
                if (type !== 'display') return '';

                return '<input type="checkbox" class="form-check-input" ' +
                       'data-role="select-row" data-id="' +
                       App.esc(row[config.select.key]) + '">';
            }
        };
    }

    /**
     * 把欄位設定轉成 DataTables 的 columns。
     */
    function buildColumns(config) {
        var columns = config.columns.map(function (col) {
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

        // 勾選欄放在最左邊，對應 table 元件在表頭多渲染的那一格
        if (config.select) {
            columns.unshift(selectColumn(config));
        }

        return columns;
    }

    /* ----------------------------------------------------------------
       勾選
       ---------------------------------------------------------------- */

    /**
     * 把畫面上的 checkbox 對回記住的勾選狀態。每次重畫都要跑一次。
     */
    function syncSelection(wrap, config, state) {
        var tableEl = document.getElementById(config.id);
        if (!tableEl) return;

        Array.prototype.forEach.call(
            tableEl.querySelectorAll('[data-role="select-row"]'),
            function (box) {
                box.checked = !!state.selected[String(box.getAttribute('data-id'))];
            }
        );

        syncHeader(wrap, tableEl);
    }

    /**
     * 表頭那顆全選鈕的三種狀態：全勾、全不勾、勾了一部分。
     */
    function syncHeader(wrap, tableEl) {
        var head = wrap.querySelector('[data-role="select-page"]');
        if (!head) return;

        var all     = tableEl.querySelectorAll('[data-role="select-row"]').length;
        var checked = tableEl.querySelectorAll('[data-role="select-row"]:checked').length;

        head.checked       = all > 0 && all === checked;
        head.indeterminate = checked > 0 && checked < all;
    }

    /**
     * 工具列上的「已勾選 N 筆」。
     *
     * 這個數字不是裝飾用的：按下「全選查詢結果」之後，畫面上只有這一頁的
     * checkbox 會變勾，不報數字的話使用者無從得知後面幾百筆有沒有被選到。
     */
    function syncInfo(wrap, count) {
        var info = wrap.querySelector('[data-role="select-info"]');
        if (!info) return;

        info.hidden = count === 0;

        var el = info.querySelector('[data-role="select-count"]');
        if (el) el.textContent = String(count);
    }

    /**
     * 勾選有變動時通知外面（合計列這類東西靠這個更新）。
     */
    function fireSelect(wrap, instance) {
        var selected = instance.selected();

        syncInfo(wrap, selected.length);

        wrap.dispatchEvent(new CustomEvent('app:table:select', {
            bubbles: true,
            detail: { id: instance.id, selected: selected }
        }));
    }

    /**
     * 勾選的事件綁定。
     *
     * 資料列用事件委派綁在表格上，換頁重畫之後不需要重新綁 —— 逐列去綁的話，
     * 每次 draw 都要先解除舊的，漏一次就會變成點一下算兩次。
     */
    function bindSelection(wrap, config, state, instance) {
        var tableEl = document.getElementById(config.id);
        if (!tableEl) return;

        tableEl.addEventListener('change', function (e) {
            var box = e.target;
            if (!box.getAttribute || box.getAttribute('data-role') !== 'select-row') return;

            var id = String(box.getAttribute('data-id'));

            if (box.checked) {
                state.selected[id] = true;
            } else {
                delete state.selected[id];
            }

            syncHeader(wrap, tableEl);
            fireSelect(wrap, instance);
        });

        var head = wrap.querySelector('[data-role="select-page"]');
        if (head) {
            head.addEventListener('change', function () {
                var checked = head.checked;

                Array.prototype.forEach.call(
                    tableEl.querySelectorAll('[data-role="select-row"]'),
                    function (box) {
                        box.checked = checked;

                        var id = String(box.getAttribute('data-id'));
                        if (checked) {
                            state.selected[id] = true;
                        } else {
                            delete state.selected[id];
                        }
                    }
                );

                head.indeterminate = false;
                fireSelect(wrap, instance);
            });
        }

        var all = wrap.querySelector('[data-role="select-all-matching"]');
        if (all) {
            all.addEventListener('click', function () {
                instance.selectAllMatching();
            });
        }

        var clear = wrap.querySelector('[data-role="select-clear"]');
        if (clear) {
            clear.addEventListener('click', function () {
                instance.clearSelection();
            });
        }
    }

    /**
     * 頁面上有沒有查詢條件列指名要重新載入這張表。
     *
     * 條件列與表格是各自初始化的，這裡直接看 DOM 而不是靠註冊，
     * 順序就不會有先後問題（表格先建立時條件列的 HTML 已經在頁面上了）。
     */
    function hasFilterOwner(tableId) {
        var forms = document.querySelectorAll('.app-filter[data-filter-target]');

        for (var i = 0; i < forms.length; i++) {
            var targets = String(forms[i].getAttribute('data-filter-target')).split(',');

            for (var j = 0; j < targets.length; j++) {
                if (targets[j].trim() === tableId) return true;
            }
        }

        return false;
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

        /**
         * DataTables 的欄位索引要算上勾選欄。
         *
         * config.columns 是「資料欄位」的清單，勾選欄不在裡面（它不是資料，
         * 是 buildColumns 額外插在最前面的）。少加這一格的話初始排序會整個
         * 往左位移一欄 —— 排序箭頭跑到勾選欄上，送給後端的 sort 也是隔壁那一欄。
         */
        var offset = config.select ? 1 : 0;

        for (var i = 0; i < config.columns.length; i++) {
            if (config.columns[i].key === config.sort) {
                return [[i + offset, config.dir === 'desc' ? 'desc' : 'asc']];
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

        /**
         * 這張表是不是掛在某個查詢條件列底下。
         *
         * 是的話，第一次載入的查詢條件要由條件列提供（它會在頁面載入時
         * 呼叫 App.table.prime），表格自己不能搶先打一次沒帶條件的 API——
         * 那次請求會因為缺少必填的日期區間被後端擋下，
         * 使用者一進頁面就看到紅色錯誤訊息。
         */
        var ownedByFilter = hasFilterOwner(config.id);

        var state = {
            params: {},                      // 目前的查詢條件

            /**
             * 勾起來的識別碼。
             *
             * 刻意不隨查詢條件清空：使用者的習慣常常是「查一批、勾幾筆，
             * 再換條件查、再勾幾筆」，最後一次送出。查一次就清掉的話
             * 這種用法會做不下去。要清空有工具列上的「取消全選」。
             */
            selected: {},

            /**
             * 是否可以真的去打 API。
             *
             * 光是不呼叫 reload 沒有用——DataTables 在 serverSide 模式下
             * 初始化時就會自己送出第一次請求，所以要在 ajax 這一層直接擋掉。
             *
             *   auto = false        要等使用者按下查詢才載入
             *   掛在條件列底下       要等條件列把預設條件送過來
             */
            ready: config.auto !== false && !ownedByFilter
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

                // 這一頁的 checkbox 是剛畫出來的，要對回記住的勾選狀態
                if (config.select) syncSelection(wrap, config, state);
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

            /**
             * 頁面載入時由查詢條件列呼叫：先把預設條件交給表格。
             *
             * auto = true  的表格到這一刻才做第一次查詢（條件是齊的）
             * auto = false 的表格只記住條件，等使用者按查詢
             */
            prime: function (params) {
                if (params) state.params = params;

                if (config.auto !== false) {
                    instance.reload(null, true);
                }
            },

            /** 目前的查詢條件（匯出時要帶上） */
            params: function () {
                return state.params;
            },

            /** 勾起來的識別碼 */
            selected: function () {
                return Object.keys(state.selected);
            },

            setSelected: function (ids) {
                state.selected = {};

                (ids || []).forEach(function (id) {
                    state.selected[String(id)] = true;
                });

                syncSelection(wrap, config, state);
                fireSelect(wrap, instance);
            },

            clearSelection: function () {
                instance.setSelected([]);
            },

            /**
             * 全選「這次查到的全部」，不只是這一頁。
             *
             * 後端分頁的情況下，使用者想要的通常是整個查詢結果；
             * 讓前端翻完所有頁去收集太慢，所以請後端一次把命中的識別碼給我們。
             */
            selectAllMatching: function () {
                if (!config.select || !config.select.ids) {
                    return Promise.resolve([]);
                }

                return App.http
                    .get(config.select.ids, state.params, { message: '取得查詢結果…' })
                    .then(function (result) {
                        var ids = result.ids || [];
                        instance.setSelected(ids);

                        return ids;
                    })
                    .catch(function () { return []; });
            }
        };

        instances[config.id] = instance;

        bindDrill(wrap);
        bindExport(wrap, instance);
        bindRefresh(wrap, instance);

        if (config.select) bindSelection(wrap, config, state, instance);

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

    /** 把 'a,b' 或 ['a','b'] 統一成逐一處理 */
    function eachId(ids, fn) {
        (Array.isArray(ids) ? ids : String(ids).split(','))
            .map(function (s) { return s.trim(); })
            .filter(Boolean)
            .forEach(fn);
    }

    App.table = {
        get: function (id) {
            return instances[id] || null;
        },

        reload: function (id, params, resetPage) {
            var instance = instances[id];
            if (instance) instance.reload(params, resetPage);
        },

        /**
         * 一次重載多張表（例如同一組查詢條件對應明細與統計兩張表）。
         * resetPage 傳 false 表示留在目前這一頁——匯入完順手刷新用的，
         * 換了查詢條件則不要傳，回到第一頁才對。
         */
        reloadAll: function (ids, params, resetPage) {
            eachId(ids, function (id) {
                App.table.reload(id, params, resetPage);
            });
        },

        /**
         * 頁面載入時把預設查詢條件交給表格。
         * 跟 reloadAll 的差別：auto = false 的表格只收下條件，不會被強制查詢。
         */
        primeAll: function (ids, params) {
            eachId(ids, function (id) {
                var instance = instances[id];
                if (instance) instance.prime(params);
            });
        },

        /** 勾起來的識別碼 */
        selected: function (id) {
            var instance = instances[id];

            return instance ? instance.selected() : [];
        },

        setSelected: function (id, ids) {
            var instance = instances[id];
            if (instance) instance.setSelected(ids);
        },

        clearSelection: function (ids) {
            eachId(ids, function (id) {
                var instance = instances[id];
                if (instance) instance.clearSelection();
            });
        },

        /** 全選這次查到的全部（不只這一頁） */
        selectAllMatching: function (id) {
            var instance = instances[id];

            return instance ? instance.selectAllMatching() : Promise.resolve([]);
        },

        /**
         * 重算欄寬。
         *
         * DataTables 的欄寬是初始化那一刻量出來寫死在 style 上的，
         * 容器寬度後來變了它不會自己跟上。容器從隱藏變成顯示、
         * 或旁邊／上面的東西收合掉導致可用寬度改變時，要叫這個。
         *
         * 還沒載入過的表格跳過——裡面沒有資料列，量不出東西，
         * 反而可能把欄寬定死在空表的寬度。
         */
        adjustAll: function (ids) {
            eachId(ids, function (id) {
                var instance = instances[id];
                if (instance && instance.loaded) instance.dt.columns.adjust();
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
