<?php
/**
 * 排程匯入的說明文字。
 * 抽成獨立檔案是為了讓文案可以單獨修改，不用動到版面。
 */
?>
<ol class="app-steps">
    <li>
        <strong>下載範本</strong>
        <span>照著範本填，欄位名稱不要改。日期一天一個檔或多天放同一個檔都可以。</span>
    </li>
    <li>
        <strong>上傳檔案</strong>
        <span>拖進來或按選擇檔案。這一步<u>還不會寫入資料庫</u>，只做檢查。</span>
    </li>
    <li>
        <strong>看檢查結果</strong>
        <span>有問題會列出第幾列、哪一欄、為什麼不行。改完重新上傳。</span>
    </li>
    <li>
        <strong>確認匯入</strong>
        <span>沒有問題才按得下去。整批一起寫入，不會匯到一半停住。</span>
    </li>
</ol>

<h4 class="app-panel__subtitle">會怎麼寫入</h4>

<p class="app-panel__hint">
    以<strong>日期＋排程代號＋產品別＋線別</strong>四個欄位比對：
    沒有的新增、已經有的更新數量。所以同一天可以重複上傳，
    早上傳一次排程、下班前再傳一次實績，不會變成兩筆。
</p>

<h4 class="app-panel__subtitle">會被擋下來的狀況</h4>

<div class="app-record app-record--cols1 app-record--plain">
    <div class="app-record__grid">
        <div class="app-record__item">
            <div class="app-record__label">同一條線出現兩次</div>
            <div class="app-record__value">同一天、同排程、同產品別、同線別只能有一列，否則合計會少算。</div>
        </div>
        <div class="app-record__item">
            <div class="app-record__label">實際超過預計兩倍</div>
            <div class="app-record__value">通常是多打了一個 0。真的是超前達標的話，把預計數量也一起更正。</div>
        </div>
        <div class="app-record__item">
            <div class="app-record__label">產品別填代號</div>
            <div class="app-record__value">檔案上填「白片」「彩片」就好，代號由系統轉換。</div>
        </div>
        <div class="app-record__item">
            <div class="app-record__label">開起來是亂碼</div>
            <div class="app-record__value">不用處理。Excel 存出來的 Big5 檔系統讀得進來。</div>
        </div>
    </div>
</div>

<?php if (config('app.demo_mode')): ?>
    <div class="app-note-box">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>
            目前是<strong>示範模式</strong>，按下確認匯入不會真的寫入資料庫，
            只會在 <code>storage/logs</code> 留一筆記錄。檔案解析與驗證都是真的。
        </div>
    </div>
<?php endif; ?>
