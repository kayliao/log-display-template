/**
 * API 呼叫封裝。
 *
 * 全系統只透過這裡打 API，好處是這些事只寫一次：
 *   - 統一解析後端的信封格式 { ok, code, message, data, trace_id }
 *   - 失敗自動跳訊息並附上 trace_id（現場報修時報這串就能查 log）
 *   - 401 自動導回登入頁
 *   - 自動顯示 / 關閉 loading
 *   - 使用者有操作就順便延長 Session
 *   - shared: true 時，同一個網址正在跑就共用那一次呼叫（同面板多張卡共用一支 API 用）
 *
 * 用法：
 *   App.http.get('/api/machine/list.php', { area: 'A' })
 *           .then(function (data) { ... });
 *
 * resolve 拿到的是信封裡的 data，不是整包回應。
 * 業務失敗（ok=false）會 reject，所以 then 裡面不需要再判斷成功與否。
 */
window.App = window.App || {};

(function (App) {
    'use strict';

    // 同一支檔案被載入兩次時，第二次直接跳出（原因見 app.core.js 開頭）
    App.__loaded = App.__loaded || {};
    if (App.__loaded.http) return;
    App.__loaded.http = true;

    function buildUrl(path, params) {
        var url = App.url(path);
        if (!params) return url;

        var qs = Object.keys(params)
            .filter(function (k) {
                return params[k] !== null && params[k] !== undefined && params[k] !== '';
            })
            .map(function (k) {
                return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
            })
            .join('&');

        if (!qs) return url;

        return url + (url.indexOf('?') === -1 ? '?' : '&') + qs;
    }

    /**
     * 進行中的共用呼叫（shared: true 才會進來），key 就是完整網址。
     *
     * 「今日統整」那種面板是好幾張卡指到同一支 API 吃同一份回應
     * （數字小卡取 tiles、分佈卡取 cycles、達成率卡取 achv）。
     * 各查各的等於把同一組 SQL 跑三次。
     *
     * 只認網址，不管呼叫端各自帶的 quiet / block ——
     * 遮罩是各蓋各的（在 request 裡處理），錯誤訊息則刻意只跳一次。
     */
    var inflight = {};

    function send(url, init, quiet) {
        return fetch(url, init)
            .then(function (res) {
                // 後端一定回 JSON；回不出 JSON 代表伺服器層級出事了
                return res.json()
                    .catch(function () {
                        throw {
                            message: '伺服器回應格式錯誤（HTTP ' + res.status + '），請聯絡資訊人員。',
                            status: res.status
                        };
                    })
                    .then(function (body) {
                        return { res: res, body: body };
                    });
            })
            .then(function (r) {
                var body = r.body;

                if (r.res.status === 401) {
                    // 登入逾時：導回登入頁，不要再跳一堆錯誤訊息
                    window.location.href = App.url('/login.php?expired=1');
                    throw { message: body.message || '登入已逾時', handled: true };
                }

                if (!body.ok) {
                    throw {
                        message: body.message || '操作失敗',
                        traceId: body.trace_id,
                        status: r.res.status,
                        data: body.data
                    };
                }

                // 有成功互動就順延 Session
                if (App.session && App.session.touch) App.session.touch();

                return body.data;
            })
            .catch(function (err) {
                if (err && err.handled) throw err;

                var message = (err && err.message) ? err.message : '連線失敗，請確認網路或稍後再試。';

                if (!quiet) {
                    App.toast(message, 'error', err && err.traceId);
                }

                throw err;
            });
    }

    /**
     * @param {object} options
     *   loading  false 表示不顯示遮罩（背景輪詢用）
     *   quiet    true 表示失敗時不跳訊息，由呼叫端自己處理
     *   block    傳入元素表示改用區塊型遮罩
     *   shared   true 表示同一個網址正在跑的話就共用那一次（只有 GET 有效）
     */
    function request(method, path, payload, options) {
        options = options || {};

        var url  = method === 'GET' ? buildUrl(path, payload) : App.url(path);
        var init = {
            method: method,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            credentials: 'same-origin',
            cache: 'no-store'
        };

        if (method !== 'GET' && payload) {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(payload);
        }

        var useMask = options.loading !== false && !options.block;
        var blockEl = options.block || null;

        if (useMask) App.loading.show(options.message);
        if (blockEl) App.loading.block(blockEl, true);

        var call;

        if (options.shared && method === 'GET') {
            if (!inflight[url]) {
                inflight[url] = send(url, init, options.quiet)
                    .finally(function () { delete inflight[url]; });
            }

            call = inflight[url];
        } else {
            call = send(url, init, options.quiet);
        }

        /**
         * 遮罩的收尾接在「這一次呼叫」上而不是共用的那一支：
         * 共用時每張卡的遮罩各蓋各的，要各自收各自的。
         */
        return call.finally(function () {
            if (useMask) App.loading.hide();
            if (blockEl) App.loading.block(blockEl, false);
        });
    }

    App.http = {
        get: function (path, params, options) {
            return request('GET', path, params, options);
        },

        post: function (path, payload, options) {
            return request('POST', path, payload, options);
        },

        /** 下載檔案（CSV 匯出）。直接開新視窗，不走 fetch。 */
        download: function (path, params) {
            window.location.href = buildUrl(path, params);
        }
    };

})(window.App);
