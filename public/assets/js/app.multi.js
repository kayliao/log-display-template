/**
 * 一次輸入多筆的搜尋框（field 的 type => multi）。
 *
 * 這支只做兩件事：顯示「已輸入幾筆」、一鍵清空。
 *
 * 刻意不在前端把字串切開再送出——
 * 送出的就是使用者看到的那串文字，由後端 Request::multi() 負責切。
 * 兩邊只有一套切分規則，現場回報「我明明有貼進去」的時候才查得下去；
 * 前端也切一次的話，遲早會出現兩邊認定的筆數不一樣。
 *
 * 分隔符號：逗號、頓號、分號、換行、Tab、空白（半形全形都算）。
 */
window.App = window.App || {};

(function (App) {
    'use strict';

    // 同一支檔案被載入兩次時，第二次直接跳出（原因見 app.core.js 開頭）
    App.__loaded = App.__loaded || {};
    if (App.__loaded.multi) return;
    App.__loaded.multi = true;

    // 跟後端 Request::multi() 用同一組分隔符號
    var SEPARATORS = /[,;\s、，；]+/;

    /**
     * 把輸入字串切成值清單（去空白、去重複）。
     * 對外開放，頁面要自己算筆數時可以直接用。
     */
    function parse(text) {
        var out = [];
        var seen = {};

        String(text || '').split(SEPARATORS).forEach(function (part) {
            var value = part.trim();
            if (value === '') return;

            // 用前綴避開跟 Object 內建屬性撞名（例如有人真的輸入 constructor）
            var key = '#' + value;
            if (seen[key]) return;

            seen[key] = true;
            out.push(value);
        });

        return out;
    }

    function init(wrap) {
        var input = wrap.querySelector('.app-multi__input');
        var count = wrap.querySelector('[data-role="multi-count"]');
        var clear = wrap.querySelector('[data-role="multi-clear"]');
        var limit = parseInt(wrap.getAttribute('data-limit'), 10) || 0;

        if (!input) return;

        function refresh() {
            var values = parse(input.value);
            var over   = limit > 0 && values.length > limit;

            if (count) {
                count.textContent = values.length === 0
                    ? ''
                    : (over
                        ? '已輸入 ' + values.length + ' 筆，超過上限 ' + limit + ' 筆'
                        : '已輸入 ' + values.length + ' 筆');

                count.classList.toggle('app-multi__count--over', over);
            }

            if (clear) clear.hidden = input.value === '';
        }

        input.addEventListener('input', refresh);

        // 貼上是主要的輸入方式，瀏覽器要等一個 tick 才拿得到貼進去的內容
        input.addEventListener('paste', function () { setTimeout(refresh, 0); });

        if (clear) {
            clear.addEventListener('click', function () {
                input.value = '';
                refresh();
                input.focus();
            });
        }

        refresh();
    }

    App.multiInput = { init: init, parse: parse };

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(
            document.querySelectorAll('[data-role="multi-input"]'),
            init
        );
    });

})(window.App);
