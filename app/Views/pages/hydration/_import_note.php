<?php
/**
 * 水化排程匯入的規則說明。
 * 抽成獨立檔案是為了讓文案可以單獨修改，不用動到版面。
 */
?>
<h4 class="app-panel__subtitle">會怎麼寫入</h4>

<p class="app-panel__hint">
    以<strong>乾片批號＋第幾次水化</strong>比對（也就是資料表的主鍵）。
    同一個乾片批號會水化好幾次，所以一個批號會有好幾列。
</p>

<div class="app-record app-record--cols1 app-record--plain">
    <div class="app-record__grid">
        <div class="app-record__item">
            <div class="app-record__label">還沒取號的那一次</div>
            <div class="app-record__value">直接覆蓋（數量、日期、封包日編碼都以新檔案為準）。</div>
        </div>
        <div class="app-record__item">
            <div class="app-record__label">已經有封包批號的那一次</div>
            <div class="app-record__value">
                不可以覆蓋 —— 號碼已經發給機台了。要記新的一次水化請把「第幾次水化」加一。
            </div>
        </div>
        <div class="app-record__item">
            <div class="app-record__label">第幾次水化</div>
            <div class="app-record__value">從 1 開始、必須連號，不可以跳號也不可以重複。</div>
        </div>
        <div class="app-record__item">
            <div class="app-record__label">水化日期怎麼填</div>
            <div class="app-record__value">
                <strong>年份放前面就好。</strong><code>2026-08-13</code>、<code>2026/08/13</code>、
                <code>20260813</code>、<code>2026年08月13日</code> 都可以，
                Excel 裡設成日期格式的儲存格也直接存出來就行。<br>
                只有 <code>08/13/2026</code> 這種年份在後面的不行 ——
                月份和日期分不出哪個是哪個，系統不會猜。
            </div>
        </div>
        <div class="app-record__item">
            <div class="app-record__label">有幾列填錯</div>
            <div class="app-record__value">
                <strong>不會擋住整批。</strong>能寫的先寫進去，寫不進去的會在匯入後用視窗列出來，
                修好那幾列再傳一次就好。
            </div>
        </div>
    </div>
</div>

<p class="app-panel__hint">
    不確定某個批號現在到第幾次了，就在下面的表格點乾片批號旁邊的放大鏡 ——
    裡面會直接寫出「下一次要填第幾次」。
</p>

<?php if (config('app.demo_mode')): ?>
    <div class="app-note-box">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>
            目前是<strong>示範模式</strong>，按下確認匯入不會真的寫入資料庫。
            檔案解析、欄位驗證與上面那些順序規則都是真的，結果視窗也照常顯示。
            右邊的「今日統整」匯完一樣會重抓一次，只是沒有真的寫入，
            重抓回來的還是同一組示範數字。
        </div>
    </div>
<?php endif; ?>
