/**
 * 通用詳細資料彈窗。
 *
 * 表格上的放大鏡點下去，就是打開這個。
 * 內容結構完全由後端決定，前端只負責照著畫——
 * 所以「彈窗裡要有幾張表、幾個區塊」是改後端 Service，不用改 JS。
 *
 * 後端回傳格式：
 *   {
 *     title: '機台 M-101',
 *     sections: [
 *       { type: 'fields', title: '基本資料',
 *         fields: [ {label, value, badge?}, ... ] },
 *
 *       { type: 'table',  title: '今日分時稼動',
 *         columns: [ {key, title, align?, format?}, ... ],
 *         rows: [ {...}, ... ] },
 *
 *       { type: 'html',   title: '備註', html: '...' }
 *     ]
 *   }
 *
 * 用法：
 *   App.modal.detail('/api/machine/detail.php', { machine_id: 'M-101' });
 */
(function (App) {
    'use strict';

    /** 可查詢區塊的設定暫存：HTML 是字串拼出來的，設定沒辦法直接掛在節點上 */
    var queries  = {};
    var querySeq = 0;

    /**
     * 單筆資料直立顯示（兩欄等寬、由左至右填）。
     *
     * 產生的 HTML 跟 PHP 的 record 元件完全一樣，
     * 所以「後端直接渲染」與「API 回傳後前端畫」兩條路長得一模一樣，
     * 改樣式時也只有一份 CSS 要改。
     *
     * 支援大項底下掛小項：
     *   fields: [
     *     { label: '機台編號', value: 'M-101' },
     *     { title: '今日產量', children: [ {label:'良品', value:1280, format:'number'}, ... ] }
     *   ]
     */
    function renderFields(section) {
        // CSS 只準備了 1~4 欄，超出範圍就退回兩欄，不要生出沒有樣式的 class
        var columns = Math.min(4, Math.max(1, parseInt(section.columns, 10) || 2));

        return '<div class="app-record app-record--cols' + columns + ' app-record--plain">' +
               renderRecordGrid(section.fields || []) +
               '</div>';
    }

    function renderRecordGrid(fields, level) {
        level = level || 0;

        return '<div class="app-record__grid">' +
               fields.map(function (f) { return renderRecordCell(f, level); }).join('') +
               '</div>';
    }

    /**
     * 層數不限：children 底下再掛 children 就多一層。
     * level 只影響縮排樣式，跟 PHP 的 record 元件是同一套規則。
     */
    function renderRecordCell(field, level) {
        if (field.children && field.children.length) {
            var cls = 'app-record__group' + (level > 0 ? ' app-record__group--sub' : '');

            return '<div class="' + cls + '">' +
                   '<div class="app-record__group-title">' + App.esc(field.title || '') + '</div>' +
                   renderRecordGrid(field.children, level + 1) +
                   '</div>';
        }

        var value;

        if (field.badge) {
            // badge 可以是字串（狀態代碼）或物件 {label, status}
            var code  = typeof field.badge === 'string' ? field.badge : (field.badge.status || field.badge.tone || 'muted');
            var text  = typeof field.badge === 'string' ? field.value : field.badge.label;
            value = '<span class="app-badge app-badge--' + App.esc(code) + '">' + App.esc(text) + '</span>';
        } else if (field.html) {
            value = field.html;                       // 由後端負責逸出
        } else {
            var formatted = App.format.apply(field, field.value, field);
            value = (formatted === '' || formatted === null || formatted === undefined)
                ? '<span class="app-record__empty">—</span>'
                : formatted;
        }

        return '<div class="app-record__item' + (field.span === 'full' ? ' app-record__item--full' : '') + '">' +
               '<div class="app-record__label">' + App.esc(field.label || '') + '</div>' +
               '<div class="app-record__value' + (field.mono ? ' app-record__value--mono' : '') + '">' + value + '</div>' +
               '</div>';
    }

    function renderTable(section) {
        var columns = section.columns || [];
        var rows    = section.rows || [];

        if (!rows.length) {
            return '<div class="app-empty app-empty--compact"><i class="bi bi-inbox"></i><p>' +
                   App.esc(section.empty || '沒有資料') + '</p></div>';
        }

        var head = columns.map(function (c) {
            var style = c.width ? ' style="width:' + parseInt(c.width, 10) + 'px"' : '';
            return '<th class="text-' + App.esc(c.align || 'left') + '"' + style + '>' +
                   App.esc(c.title) + '</th>';
        }).join('');

        var body = rows.map(function (row) {
            var tds = columns.map(function (c) {
                var cls = 'text-' + App.esc(c.align || 'left');
                if (c.format === 'number' || c.align === 'right') cls += ' app-td--number';

                return '<td class="' + cls + '">' + App.format.apply(c, row[c.key], row) + '</td>';
            }).join('');

            return '<tr>' + tds + '</tr>';
        }).join('');

        return '<div class="app-subtable__scroll">' +
               '<table class="app-subtable"><thead><tr>' + head + '</tr></thead>' +
               '<tbody>' + body + '</tbody></table></div>';
    }

    /**
     * 彈窗裡的「可查詢區塊」。
     *
     * 一般的 table 區塊是後端一次把資料算好送過來，看完就沒了。
     * 這一種多了自己的查詢條件：使用者可以在彈窗裡改條件重新查，
     * 不用關掉彈窗回到列表頁再點一次。
     *
     * 後端給的結構：
     *   { type: 'query', title: '歷史 Log 查詢',
     *     api: '/api/machine/history.php',
     *     params: { machine_id: 'M-101' },      // 每次都帶的固定參數
     *     fields: [ {type,name,label,value,options,empty}, ... ],
     *     columns: [ {key,title,align,format}, ... ],
     *     auto: true }                          // 開啟彈窗就先查一次
     *
     * API 回傳 { rows: [...] } 即可，欄位定義在 section 裡已經給了。
     */
    function renderQuery(section) {
        var id = 'mq' + (++querySeq);

        queries[id] = section;

        return '<div class="app-modalquery" data-role="modal-query" data-query-id="' + id + '">' +
               '<div class="app-modalquery__bar">' +
                   (section.fields || []).map(renderQueryField).join('') +
                   '<button type="button" class="btn btn-primary btn-sm app-modalquery__submit" ' +
                       'data-role="query-submit"><i class="bi bi-search"></i> 查詢</button>' +
               '</div>' +
               '<div class="app-modalquery__result" data-role="query-result"></div>' +
               '</div>';
    }

    /**
     * 查詢條件欄位。
     * 只支援 text / number / date / select——彈窗裡的條件本來就該少，
     * 需要更複雜的查詢請走獨立頁面。
     */
    function renderQueryField(field) {
        var name  = App.esc(field.name);
        var value = field.value === undefined || field.value === null ? '' : field.value;
        var input;

        if (field.type === 'select') {
            var options = (field.empty !== undefined && field.empty !== null)
                ? '<option value="">' + App.esc(field.empty) + '</option>'
                : '';

            options += (field.options || []).map(function (o) {
                // options 接受 [{value,text}] 或 ['A','B']
                var v = (o && o.value !== undefined) ? o.value : o;
                var t = (o && o.text  !== undefined) ? o.text  : o;

                return '<option value="' + App.esc(v) + '"' +
                       (String(value) === String(v) ? ' selected' : '') + '>' +
                       App.esc(t) + '</option>';
            }).join('');

            input = '<select class="form-select form-select-sm" name="' + name + '">' + options + '</select>';
        } else {
            input = '<input type="' + App.esc(field.type || 'text') + '" ' +
                    'class="form-control form-control-sm" name="' + name + '" ' +
                    'value="' + App.esc(value) + '">';
        }

        return '<div class="app-modalquery__field">' +
               '<label class="app-modalquery__label">' + App.esc(field.label || '') + '</label>' +
               input + '</div>';
    }

    /**
     * 把彈窗裡的查詢區塊接上事件。
     * 內容是每次開啟彈窗才產生的，所以綁定也要每次重來。
     */
    function bindQueries(body) {
        Array.prototype.forEach.call(body.querySelectorAll('[data-role="modal-query"]'), function (box) {
            var section = queries[box.getAttribute('data-query-id')];
            if (!section) return;

            var result = box.querySelector('[data-role="query-result"]');
            var button = box.querySelector('[data-role="query-submit"]');

            function run() {
                var params = {};

                Object.keys(section.params || {}).forEach(function (key) {
                    params[key] = section.params[key];
                });

                Array.prototype.forEach.call(box.querySelectorAll('[name]'), function (el) {
                    if (el.value !== '') params[el.name] = el.value;
                });

                button.disabled = true;

                App.http.get(section.api, params, { block: box, quiet: true })
                    .then(function (data) {
                        result.innerHTML = renderTable({
                            columns: section.columns,
                            rows:    (data && data.rows) || [],
                            empty:   section.empty
                        });
                    })
                    .catch(function (err) {
                        // 查詢失敗就把原因寫在區塊裡，不要蓋掉整個彈窗
                        result.innerHTML = '<div class="app-modalquery__error">' +
                            '<i class="bi bi-exclamation-circle"></i> ' +
                            App.esc((err && err.message) || '查詢失敗') +
                            (err && err.traceId ? '（代碼 ' + App.esc(err.traceId) + '）' : '') +
                            '</div>';
                    })
                    .then(function () { button.disabled = false; });
            }

            button.addEventListener('click', run);

            // 在條件欄位按 Enter 等於按查詢
            box.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
                    e.preventDefault();
                    run();
                }
            });

            if (section.auto !== false) run();
        });
    }

    function renderSection(section) {
        var inner;

        switch (section.type) {
            case 'fields': inner = renderFields(section); break;
            case 'table':  inner = renderTable(section);  break;
            case 'query':  inner = renderQuery(section);  break;
            case 'html':   inner = section.html || '';    break;   // 由後端負責逸出
            default:       inner = '';
        }

        var title = section.title
            ? '<h4 class="app-section__title">' + App.esc(section.title) + '</h4>'
            : '';

        return '<div class="app-section">' + title + inner + '</div>';
    }

    App.modal = {
        /**
         * 直接用資料開啟彈窗。
         */
        show: function (payload) {
            var modalEl = document.getElementById('appDetailModal');
            if (!modalEl) return;

            modalEl.querySelector('[data-role="detail-title"]').textContent =
                payload.title || '詳細資料';

            var body = modalEl.querySelector('[data-role="detail-body"]');

            // 上一次開啟留下的查詢設定不需要了，清掉才不會一直長大
            queries  = {};
            querySeq = 0;

            body.innerHTML = (payload.sections || []).map(renderSection).join('') ||
                '<div class="app-empty"><i class="bi bi-inbox"></i><p>沒有資料</p></div>';

            bindQueries(body);
            App.initTooltips(body);

            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        },

        /**
         * 打 API 取得內容後開啟彈窗（放大鏡的預設行為）。
         */
        detail: function (api, params) {
            return App.http.get(api, params, { message: '載入詳細資料…' })
                .then(function (data) {
                    App.modal.show(data);
                    return data;
                })
                .catch(function () { /* 錯誤訊息 App.http 已經跳過了 */ });
        },

        /**
         * 確認對話框（刪除、送出這類需要再確認的動作）。
         */
        confirm: function (message, onOk) {
            if (window.confirm(message)) {
                onOk();
            }
        }
    };

})(window.App);
