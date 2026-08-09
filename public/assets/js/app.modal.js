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

    function renderRecordGrid(fields) {
        return '<div class="app-record__grid">' +
               fields.map(renderRecordCell).join('') +
               '</div>';
    }

    function renderRecordCell(field) {
        if (field.children && field.children.length) {
            return '<div class="app-record__group">' +
                   '<div class="app-record__group-title">' + App.esc(field.title || '') + '</div>' +
                   renderRecordGrid(field.children) +
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
            return '<div class="app-empty"><i class="bi bi-inbox"></i><p>沒有資料</p></div>';
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

    function renderSection(section) {
        var inner;

        switch (section.type) {
            case 'fields': inner = renderFields(section); break;
            case 'table':  inner = renderTable(section);  break;
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

            body.innerHTML = (payload.sections || []).map(renderSection).join('') ||
                '<div class="app-empty"><i class="bi bi-inbox"></i><p>沒有資料</p></div>';

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
