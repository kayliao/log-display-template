/**
 * 查詢條件列。
 *
 * 把「按查詢 → 收集欄位 → 重新載入表格」這段固定流程集中處理，
 * 每個報表頁就不用各寫一次 onclick。
 *
 * 條件列上的 data-filter-target 指定要重新載入哪些表格（可用逗號分隔多個）。
 *
 * 行為：
 *   - 按 Enter 等於按查詢
 *   - 查詢送出時整列鎖住，避免連點造成重複查詢
 *   - 清除會還原成 old() 給的預設值（不是網址上的條件），並重新查一次
 *   - 條件列標了 collapsible 時，標題可以按著收合／展開
 *   - 條件會同步到網址列，重新整理或轉貼連結時條件還在
 *
 * 一頁有兩排以上條件列時，每一排要給 data-filter-scope（filter_bar 的 scope 參數），
 * 不然兩排都叫 keyword 的欄位會共用網址上同一個參數，
 * 重新整理後同一個字會同時填進兩排。
 */
window.App = window.App || {};

(function (App) {
    'use strict';

    // 同一支檔案被載入兩次時，第二次直接跳出（原因見 app.core.js 開頭）
    App.__loaded = App.__loaded || {};
    if (App.__loaded.filter) return;
    App.__loaded.filter = true;

    function init(form) {
        var targets = form.getAttribute('data-filter-target') || '';

        /**
         * ★ 這裡是**兩組**值，不是一組。混用會讓畫面跟資料對不起來。
         *
         *   initial ── 頁面載入時畫面上的值，也就是「載入時要用什麼條件去查」。
         *
         *       條件會被記在網址上（見 updateUrl），所以帶著條件重新整理之後，
         *       後端的 old() 會把網址上那組值填回欄位裡。要查的就是這一組——
         *       使用者看到什麼條件，資料就得是那個條件查出來的。
         *
         *   resetTo ── 「清除」按下去要還原成什麼，是 initial 蓋上後端宣告的
         *       真正預設值（data-filter-defaults，來源是欄位那份檔 old() 的
         *       第二個參數）。
         *
         *       不蓋的話：帶著條件重新整理一次，畫面上的值就是網址上那組條件，
         *       按清除等於還原成自己剛剛查的東西，看起來就像這顆按鈕壞了。
         *
         *       蓋在上面而不是整組換掉：樣板裡寫死的欄位（沒走 old()）不在
         *       名單裡，那種欄位維持原本的行為，舊頁面不會被清成空白。
         *
         * ⚠ 這兩件事以前共用同一個變數，結果是：帶著條件重新整理之後，
         *   **畫面顯示網址上的條件，資料卻是用後端預設值查的**——
         *   例如日期欄寫著上週，表格裡卻是今天的資料，而且兩邊都不報錯。
         *   要分成兩個。
         */
        var initial = App.serialize(form);
        var resetTo = {};

        Object.keys(initial).forEach(function (name) {
            resetTo[name] = initial[name];
        });

        var declared = readDefaults(form);

        Object.keys(declared).forEach(function (name) {
            resetTo[name] = declared[name];
        });

        function submit() {
            var params = App.serialize(form);

            form.classList.add('is-busy');

            /**
             * 每一種可被條件列驅動的元件都是「有載入才叫」。
             *
             * 包含 App.table 在內——一頁上只有甘特圖沒有表格時，
             * app.table.js 不一定會被載進來，寫死呼叫的話這一行就丟例外，
             * 後面的 gantt 也跟著不會重載。條件列是共用的，
             * 它不能假設頁面上一定有某一種元件。
             */
            if (App.table)       App.table.reloadAll(targets, params);
            if (App.achievement) App.achievement.reloadAll(targets, params);
            if (App.stat)        App.stat.reloadAll(targets, params);
            if (App.sum)         App.sum.reloadAll(targets, params);
            if (App.gantt)       App.gantt.reloadAll(targets, params);

            // 表格是非同步載入的，這裡用短暫延遲解除鎖定即可，
            // 真正的載入狀態由表格自己的區塊遮罩顯示
            setTimeout(function () { form.classList.remove('is-busy'); }, 300);

            // 記在網址上，重新整理或轉貼連結時條件還在
            updateUrl(form, params);
        }

        /**
         * 只綁 submit，不要再綁按鈕的 click。
         *
         * 「查詢」是 <button type="submit">，按下去瀏覽器本來就會送出 submit 事件。
         * 再多綁一個 click 的話，一次點擊會跑兩遍 submit() —— 也就是**每查一次
         * 打兩次 API**。這種重複很難從畫面上看出來（結果一樣、只是慢一倍、
         * 資料庫負擔兩倍），要開 Network 才會發現。
         *
         * 鍵盤上的 Enter 走下面那個 keydown（它 preventDefault 之後自己呼叫），
         * 所以兩種操作各自只會送一次。
         */
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            submit();
        });


        var resetBtn = form.querySelector('[data-role="filter-reset"]');
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                var ranges = form.querySelectorAll('[data-daterange-config]');

                /**
                 * ★ 先把日期區間互相牽制的限制鬆開，再設值。
                 *
                 *   兩個日曆會互相夾（選了 9/01~9/07 之後，開始日的 maxDate
                 *   就是 9/07）。直接設「今天」進去的話 flatpickr 會**默默拒絕**
                 *   ——沒有錯誤、沒有提示，畫面上就是按了清除日期沒回到預設，
                 *   看起來像這顆按鈕壞了。
                 *
                 *   設完再 couple() 把規則裝回去，不然清除之後就選得到超出上限
                 *   的區間了。快捷鍵那邊本來就有做這件事，這裡漏了。
                 */
                Array.prototype.forEach.call(ranges, function (box) {
                    if (box._appRange) box._appRange.release();
                });

                Array.prototype.forEach.call(form.querySelectorAll('[name]'), function (el) {
                    // 勾選類的欄位要還原 checked，設 value 是沒有用的
                    if (el.type === 'checkbox' || el.type === 'radio') {
                        el.checked = resetTo[el.name] !== undefined &&
                                     String(resetTo[el.name]) === el.value;
                        return;
                    }

                    el.value = resetTo[el.name] !== undefined ? resetTo[el.name] : '';
                    // 日期欄位由 flatpickr 接管，要透過它的 API 設定才會同步
                    if (el._flatpickr) el._flatpickr.setDate(el.value, false);
                });

                Array.prototype.forEach.call(ranges, function (box) {
                    if (box._appRange) box._appRange.couple();
                });

                submit();
            });
        }

        /**
         * 收合／展開。
         *
         * 只動 class，狀態記在 DOM 上，不存 localStorage——
         * 這一頁通常是好幾個人輪流用同一台現場電腦，
         * 上一個人收起來，下一個人會以為條件列壞了。
         */
        var toggle = form.querySelector('[data-role="filter-toggle"]');
        if (toggle) {
            toggle.addEventListener('click', function () {
                var collapsed = form.classList.toggle('is-collapsed');

                toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');

                /**
                 * 展開後補一次欄寬重算。
                 *
                 * 收合只改高度不改寬度，多數情況不需要，但頁面短到垂直捲軸消失時，
                 * 可用寬度會多出捲軸那十幾 px；DataTables 的欄寬是初始化時算好寫死的，
                 * 不重算就會停在舊寬度，右邊空一條或擠出橫捲軸。
                 */
                /**
                 * ⚠ 有的分支的 app.table.js 還沒有 adjustAll（例如舊的清單頁），
                 *   直接叫會丟例外。共用檔案要能在所有分支上跑，所以先問再叫。
                 */
                if (App.table && App.table.adjustAll) App.table.adjustAll(targets);
            });
        }

        /**
         * 在輸入框按 Enter 直接查詢。
         *
         * preventDefault 是必要的：不擋的話瀏覽器會再送一次 submit 事件，
         * 上面那個 handler 就跟著跑第二遍（同樣是一次操作打兩次 API）。
         * 只認 INPUT：textarea（一次貼多筆的那種欄位）要留給使用者換行。
         */
        form.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
                e.preventDefault();
                submit();
            }
        });

        /**
         * 頁面載入時把預設條件送給表格。
         *
         * 用 primeAll 而不是 reloadAll：
         *   auto = true  的表格到這一刻才做第一次查詢，條件是齊的，
         *                不會因為缺日期區間被後端擋下而跳紅色錯誤
         *   auto = false 的表格只收下條件，仍然要等使用者按查詢
         *
         * ★ 用 initial 不是 resetTo —— 送出去的條件必須跟畫面上顯示的一致，
         *   否則帶著條件重新整理之後，欄位寫著一組、資料是另一組。
         */
        if (App.table)       App.table.primeAll(targets, initial);
        if (App.achievement) App.achievement.primeAll(targets, initial);
        if (App.stat)        App.stat.primeAll(targets, initial);
        if (App.sum)         App.sum.primeAll(targets, initial);
        if (App.gantt)       App.gantt.primeAll(targets, initial);
    }

    /**
     * 把查詢條件同步到網址列，不重新載入頁面。
     *
     * 用白名單而不是「保留現有的其他參數」：網址上只會有
     * 路由參數 + 條件列的欄位，外來的雜訊查一次就被洗掉。
     *
     * 別排條件列的參數（scope[...]）也算在該留的裡面 —— 一頁兩排時，
     * 下面那排按查詢不能把上面那排的條件洗掉，
     * 否則使用者重新整理後會發現上面那排變回預設值。
     *
     * 條件列給了 scope 的話，自己的參數名寫成 scope[name]
     * （?account[keyword]=A123），PHP 那邊就是 $_GET['account']['keyword']，
     * 跟另一排的 keyword 分開。這只是網址上的寫法，送給 API 的參數名沒有變。
     */
    function updateUrl(form, params) {
        if (!window.history || !window.history.replaceState) return;

        var scope = form.getAttribute('data-filter-scope') || '';
        var keep  = keepKeys(form);
        var next  = new URLSearchParams();
        var now   = new URLSearchParams(window.location.search);

        now.forEach(function (value, key) {
            // 1. 路由參數原封不動抄回來（例如 index.php?p=<頁面>&v=<分頁>）
            if (keep.indexOf(key) > -1) {
                next.set(key, value);
                return;
            }

            // 2. 別排條件列的參數也留著（自己那一組等一下整組重寫）
            if (isOtherScope(key, scope)) next.set(key, value);
        });

        // 3. 再放這張表單有值的條件（空值不放，網址才不會一長串）
        Object.keys(params).forEach(function (key) {
            if (params[key] !== null && params[key] !== undefined && params[key] !== '') {
                next.set(urlKey(scope, key), params[key]);
            }
        });

        var qs = next.toString();

        window.history.replaceState(null, '', qs ? '?' + qs : window.location.pathname);
    }

    /**
     * 要保留哪些參數。條件列上的 data-filter-keep 優先（逗號分隔），
     * 沒寫就是預設的 p、v 兩個路由參數。
     */
    function keepKeys(form) {
        var raw = form.getAttribute('data-filter-keep')
               || ['p', 'v'].join(',');

        return String(raw).split(',')
            .map(function (s) { return s.trim(); })
            .filter(Boolean);
    }

    /** 欄位名 -> 網址上的參數名。沒給 scope 就是欄位名本人。 */
    function urlKey(scope, name) {
        return scope ? scope + '[' + name + ']' : name;
    }

    /** 這個參數是別排條件列的嗎？（長得像 scope[...]，而且 scope 不是自己） */
    function isOtherScope(key, scope) {
        var at = key.indexOf('[');

        return at > 0 && key.slice(0, at) !== scope;
    }

    /**
     * 後端印在 data-filter-defaults 上的預設值（「清除」要還原成的值）。
     *
     * 舊頁面沒有這個屬性、或者 JSON 壞掉的時候回空物件，
     * 呼叫端會自動退回「還原成頁面載入時的值」。
     */
    function readDefaults(form) {
        var raw = form.getAttribute('data-filter-defaults');
        if (!raw) return {};

        try {
            var parsed = JSON.parse(raw);
            return (parsed && typeof parsed === 'object') ? parsed : {};
        } catch (e) {
            return {};
        }
    }

    App.filter = { init: init };

    document.addEventListener('DOMContentLoaded', function () {
        // 表格要先初始化好，這裡才叫得動它，所以延到下一個事件迴圈
        setTimeout(function () {
            Array.prototype.forEach.call(document.querySelectorAll('.app-filter'), init);
        }, 0);
    });

})(window.App);
