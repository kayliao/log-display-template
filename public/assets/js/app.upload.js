/**
 * 檔案上傳匯入。
 *
 * 兩步流程：
 *   1. 選檔／拖曳 -> POST multipart 到 API -> 後端解析驗證 -> 回傳預覽與錯誤
 *   2. 使用者按「確認匯入」-> 帶著 token 再 POST 一次 -> 後端才真的寫入
 *
 * 前端不解析檔案內容。看起來多打一次 API，但這樣「驗證規則」只有後端一份：
 * 前端也驗一次的話，兩邊規則遲早會不一致，
 * 變成「畫面說可以、送出卻被擋」這種最難解釋的狀況。
 *
 * 上傳用 XMLHttpRequest 而不是 fetch，因為要顯示上傳進度（fetch 拿不到）。
 */
(function (App) {
    'use strict';

    function init(wrap) {
        var config = App.readConfig(wrap, 'data-upload-config');
        if (!config) return null;

        var drop     = wrap.querySelector('[data-role="drop"]');
        var input    = wrap.querySelector('[data-role="file"]');
        var info     = wrap.querySelector('[data-role="file-info"]');
        var nameEl   = wrap.querySelector('[data-role="file-name"]');
        var sizeEl   = wrap.querySelector('[data-role="file-size"]');
        var removeEl = wrap.querySelector('[data-role="file-remove"]');
        var result   = wrap.querySelector('[data-role="result"]');
        var actions  = wrap.querySelector('[data-role="actions"]');
        var commitEl = wrap.querySelector('[data-role="commit"]');
        var resetEl  = wrap.querySelector('[data-role="reset"]');

        var token = null;

        // ---------------------------------------------------------------- 選檔
        input.addEventListener('change', function () {
            if (input.files && input.files[0]) choose(input.files[0]);
        });

        // 拖曳。dragover 一定要 preventDefault，否則瀏覽器會直接開啟那個檔案
        ['dragenter', 'dragover'].forEach(function (type) {
            drop.addEventListener(type, function (e) {
                e.preventDefault();
                drop.classList.add('is-over');
            });
        });

        ['dragleave', 'drop'].forEach(function (type) {
            drop.addEventListener(type, function (e) {
                e.preventDefault();
                drop.classList.remove('is-over');
            });
        });

        drop.addEventListener('drop', function (e) {
            var files = e.dataTransfer && e.dataTransfer.files;
            if (files && files[0]) choose(files[0]);
        });

        removeEl.addEventListener('click', reset);
        resetEl.addEventListener('click', reset);

        function choose(file) {
            // 這兩項在前端先擋，是為了不要讓使用者白等一個註定失敗的上傳。
            // 後端仍然會再驗一次，前端的檢查不算數。
            var maxBytes = config.maxSize * 1024 * 1024;

            if (file.size > maxBytes) {
                App.toast('檔案大小 ' + formatSize(file.size) + ' 超過上限 ' +
                          config.maxSize + ' MB，請分批上傳。', 'error');
                return;
            }

            var ext = '.' + (file.name.split('.').pop() || '').toLowerCase();

            if (config.accept && config.accept.split(',').indexOf(ext) === -1) {
                App.toast('只接受 ' + config.accept + ' 檔。', 'error');
                return;
            }

            nameEl.textContent = file.name;
            sizeEl.textContent = formatSize(file.size);
            info.hidden        = false;

            upload(file);
        }

        function reset() {
            token          = null;
            input.value    = '';
            info.hidden    = true;
            result.hidden  = true;
            actions.hidden = true;
            result.innerHTML = '';
        }

        // ---------------------------------------------------------------- 上傳
        function upload(file) {
            var form = new FormData();
            form.append('file', file);
            form.append('action', 'preview');

            result.hidden   = false;
            result.innerHTML = '<div class="app-upload__progress">' +
                               '<div class="app-upload__progress-bar" data-role="bar"></div></div>' +
                               '<div class="app-upload__status">上傳中…</div>';

            var bar = result.querySelector('[data-role="bar"]');
            var xhr = new XMLHttpRequest();

            xhr.open('POST', App.url(config.api));
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.withCredentials = true;

            xhr.upload.addEventListener('progress', function (e) {
                if (e.lengthComputable && bar) {
                    bar.style.width = Math.round(e.loaded / e.total * 100) + '%';
                }
            });

            xhr.addEventListener('load', function () {
                var body;

                try {
                    body = JSON.parse(xhr.responseText);
                } catch (err) {
                    showError('伺服器回應格式錯誤（HTTP ' + xhr.status + '），請聯絡資訊人員。');
                    return;
                }

                if (xhr.status === 401) {
                    window.location.href = App.url('/login.php?expired=1');
                    return;
                }

                if (!body.ok) {
                    showError(body.message || '檔案處理失敗', body.trace_id);
                    return;
                }

                token = body.data.token;
                showPreview(body.data);
            });

            xhr.addEventListener('error', function () {
                showError('連線失敗，請確認網路或稍後再試。');
            });

            xhr.send(form);
        }

        // ---------------------------------------------------------------- 顯示
        function showError(message, traceId) {
            actions.hidden   = false;
            commitEl.disabled = true;

            result.innerHTML =
                '<div class="app-upload__alert app-upload__alert--error">' +
                '<i class="bi bi-x-circle-fill"></i><div>' + App.esc(message) +
                (traceId ? '<div class="app-upload__trace">代碼 ' + App.esc(traceId) + '</div>' : '') +
                '</div></div>';
        }

        function showPreview(data) {
            var clean = data.errors.length === 0;

            var html = '<div class="app-upload__summary">' +
                stat('讀到', data.total, '列') +
                stat('可匯入', data.valid, '筆', clean ? 'ok' : '') +
                stat('新增', data.insert, '筆') +
                stat('更新', data.update, '筆') +
                stat('有問題', data.errorTotal, '處', data.errorTotal > 0 ? 'bad' : '') +
                '</div>';

            if (!clean) {
                /**
                 * partial 模式：有問題的列只是「會被跳過」，不擋整批。
                 * 話要講清楚，不然使用者不知道按下確認之後到底會發生什麼事。
                 */
                var tone = config.partial ? 'warning' : 'error';
                var note = config.partial
                    ? '有 ' + data.errorTotal + ' 處有問題，這幾列會被<u>跳過</u>，其餘 ' +
                      data.valid + ' 筆仍會匯入。'
                    : '檔案有 ' + data.errorTotal + ' 處需要修正，改好後重新上傳。';

                html += '<div class="app-upload__alert app-upload__alert--' + tone + '">' +
                        '<i class="bi bi-exclamation-triangle-fill"></i>' +
                        '<div>' + note +
                        (data.errorTotal > data.errors.length
                            ? '（以下只列出前 ' + data.errors.length + ' 處）' : '') +
                        '</div></div>';

                html += '<div class="app-subtable__scroll"><table class="app-subtable">' +
                        '<thead><tr><th style="width:70px">第幾列</th><th style="width:120px">欄位</th>' +
                        '<th style="width:160px">內容</th><th>問題</th></tr></thead><tbody>' +
                        data.errors.map(function (e) {
                            return '<tr><td class="app-td--number">' + App.esc(e.line) + '</td>' +
                                   '<td>' + App.esc(e.column) + '</td>' +
                                   '<td class="app-td--mono">' + App.esc(e.value || '（空白）') + '</td>' +
                                   '<td>' + App.esc(e.message) + '</td></tr>';
                        }).join('') +
                        '</tbody></table></div>';
            } else if (data.preview.length) {
                html += '<h4 class="app-upload__spec-title">預覽（前 ' + data.preview.length + ' 筆）</h4>';
                html += '<div class="app-subtable__scroll"><table class="app-subtable">' +
                        '<thead><tr><th style="width:70px">動作</th>' +
                        data.columns.map(function (c) {
                            return '<th>' + App.esc(c) + '</th>';
                        }).join('') + '</tr></thead><tbody>' +
                        data.preview.map(function (row) {
                            var act = row._act === 'update'
                                ? '<span class="app-badge app-badge--soft app-badge--warning">更新</span>'
                                : '<span class="app-badge app-badge--soft app-badge--success">新增</span>';

                            return '<tr><td>' + act + '</td>' +
                                   keysOf(row).map(function (k) {
                                       return '<td>' + App.esc(row[k]) + '</td>';
                                   }).join('') + '</tr>';
                        }).join('') +
                        '</tbody></table></div>';
            }

            result.innerHTML  = html;
            result.hidden     = false;
            actions.hidden    = false;

            // partial 模式只要有一筆可以寫就給按；否則要全部乾淨才給按
            commitEl.disabled = config.partial
                ? data.valid === 0
                : (!clean || data.valid === 0);
        }

        /** 資料列裡真正的欄位（跳過底線開頭的內部欄位） */
        function keysOf(row) {
            return Object.keys(row).filter(function (k) { return k.charAt(0) !== '_'; });
        }

        function stat(label, value, unit, tone) {
            return '<div class="app-upload__stat' + (tone ? ' app-upload__stat--' + tone : '') + '">' +
                   '<span class="app-upload__stat-label">' + App.esc(label) + '</span>' +
                   '<span class="app-upload__stat-value">' + App.esc(value) +
                   '<i>' + App.esc(unit) + '</i></span></div>';
        }

        // ---------------------------------------------------------------- 確認匯入
        commitEl.addEventListener('click', function () {
            if (!token) return;

            commitEl.disabled = true;

            App.http.post(config.api, { action: 'commit', token: token }, { message: '匯入中…' })
                .then(function (data) {
                    var failed = data.failed || 0;

                    App.toast(
                        '匯入完成：新增 ' + data.insert + ' 筆、更新 ' + data.update + ' 筆。' +
                        (failed ? '另有 ' + failed + ' 筆沒有寫入。' : ''),
                        failed ? 'warning' : 'success'
                    );

                    /**
                     * 後端給了結果報告就用彈窗顯示。
                     *
                     * 部分成功用一行 toast 是講不清楚的：使用者要知道哪幾列沒進去、
                     * 為什麼，而且要能照著那張表回去改檔案。
                     * 內容結構由後端決定（跟放大鏡彈窗同一套），前端不用改。
                     */
                    if (data.report) {
                        App.modal.show(data.report);
                    }

                    reset();

                    // 頁面上如果有表格，匯完順手刷新，使用者不用自己按重新整理
                    if (wrap.getAttribute('data-reload-table')) {
                        App.table.reload(wrap.getAttribute('data-reload-table'), null, false);
                    }
                })
                .catch(function () {
                    commitEl.disabled = false;
                });
        });

        function formatSize(bytes) {
            return bytes >= 1048576
                ? (bytes / 1048576).toFixed(1) + ' MB'
                : Math.max(1, Math.round(bytes / 1024)) + ' KB';
        }

        return { reset: reset };
    }

    App.upload = { init: init };

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(
            document.querySelectorAll('[data-upload-config]'),
            init
        );
    });

})(window.App);
