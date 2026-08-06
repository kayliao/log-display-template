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

    function renderFields(section) {
        var rows = (section.fields || []).map(function (f) {
            var value = f.badge
                ? '<span class="app-badge app-badge--' + App.esc(f.badge) + '">' + App.esc(f.value) + '</span>'
                : App.esc(f.value);

            return '<div class="app-fields__row">' +
                   '<span class="app-fields__label">' + App.esc(f.label) + '</span>' +
                   '<span class="app-fields__value">' + value + '</span>' +
                   '</div>';
        }).join('');

        return '<div class="app-fields">' + rows + '</div>';
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
