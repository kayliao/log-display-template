<?php
/**
 * 匯入頁的說明文字。
 * 抽成獨立檔案是為了讓文案可以單獨修改，不用動到版面。
 */

use App\Core\View;
?>
<ol class="app-steps">
    <li>
        <strong>下載範本</strong>
        <span>照著範本填，欄位名稱不要改。</span>
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
    以<strong>機台編號</strong>比對：資料庫裡沒有的新增、已經有的更新。
    不會刪除任何資料，所以檔案裡少了某台機器不代表它會被移除。
</p>

<h4 class="app-panel__subtitle">常見狀況</h4>

<div class="app-record app-record--cols1 app-record--plain">
    <div class="app-record__grid">
        <div class="app-record__item">
            <div class="app-record__label">開起來是亂碼</div>
            <div class="app-record__value">不用處理。Excel 存出來的 Big5 檔系統讀得進來。</div>
        </div>
        <div class="app-record__item">
            <div class="app-record__label">欄位對不到</div>
            <div class="app-record__value">檢查第一列是不是欄位名稱，且沒有被改字或多空格。</div>
        </div>
        <div class="app-record__item">
            <div class="app-record__label">檔案太大</div>
            <div class="app-record__value">單檔 5 MB、一次 5000 列，超過請分批。</div>
        </div>
        <div class="app-record__item">
            <div class="app-record__label">按了確認沒反應</div>
            <div class="app-record__value">檢查結果還有紅字時按不下去，要先把問題改掉。</div>
        </div>
    </div>
</div>

<?php if (config('app.demo_mode')): ?>
    <div class="app-note-box">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <!-- 文字要包一層。外層是 flex，不包的話每個文字節點與 <strong> 都會
             各自變成一個 flex item，整段就被拆成一直排。 -->
        <div>
            目前是<strong>示範模式</strong>，按下確認匯入不會真的寫入資料庫，
            只會在 <code>storage/logs</code> 留一筆記錄。檔案解析與驗證都是真的。
        </div>
    </div>
<?php endif; ?>
