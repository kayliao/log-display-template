# 怎麼把它跑起來

三種情境，看你要哪一種。**沒有網路也全部做得到**，這個 repo 已經包含所有相依套件。

---

## 情境一：我只想先看看畫面長怎樣（最快）

**按兩下專案根目錄的 `start.bat`。**

它會自動找出這台電腦的 PHP、挑一個沒被占用的埠、啟動伺服器、開啟瀏覽器。

```
帳號 admin   密碼 admin
```

要關掉伺服器：在那個黑色視窗按 `Ctrl+C`，或直接關掉視窗。

> 第一次開起來會看到黃色的「示範模式」提示列 —— 那是內建的假資料
> （48 台機台、260 筆 Log），讓你不用先接資料庫就能看到完整效果。

### 如果 `start.bat` 說找不到 PHP

它會把三個解法印在畫面上，簡述如下：

1. **公司現有系統就在跑 PHP** —— 去找它的安裝位置（常見於 `C:\xampp\php\`，
   或 IIS 管理員的 FastCGI 設定裡有完整路徑），把整個資料夾複製到本專案底下
   並改名為 `php`。最後長這樣：`專案\php\php.exe`。再按兩下 `start.bat`。

2. **從有網路的電腦下載免安裝版**（本專案在 7.2.24 實測過）：
   <https://windows.php.net/downloads/releases/archives/> 的
   `php-7.2.24-nts-Win32-VC15-x64.zip`，解壓到專案底下的 `php` 資料夾。

3. **不跑測試伺服器**，直接放進公司現有的 Apache / IIS —— 見情境二。

### 其他啟動方式

PHP 已經在 PATH 裡的話，命令列一行就好：

```powershell
php -S 127.0.0.1:8099 -t public
```

PHP 不在 PATH，就用完整路徑：

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8099 -t public
```

---

## 情境二：放進公司現有的 Apache / IIS

這才是正式的跑法。`start.bat` 那個內建伺服器只適合一個人看畫面，
**不要拿去給整個廠區用**（它一次只處理一個請求）。

把整個專案資料夾複製到網站目錄底下，然後二選一：

### A. 網站根目錄可以指到 `public\`（建議）

安全性最好，`app\`、`config\`、`vendor\`、`storage\` 完全無法從瀏覽器存取。

- **Apache**：`DocumentRoot` 指到 `專案\public`
- **IIS**：網站的實體路徑指到 `專案\public`

設定完成後，根目錄的 `index.php`、`.htaccess`、`web.config` 可以刪掉。

### B. 網站根目錄只能指到專案資料夾（不用改 vhost）

由根目錄的 `index.php` 轉發，`.htaccess`（Apache）或 `web.config`（IIS）
負責擋掉不該被存取的目錄。這兩個檔案我已經備好了，放著就會生效。

> **用這個方式一定要驗證擋檔有效**：
> 瀏覽器打 `你的網址/config/database.php`，
> 應該要出現 403，**如果是下載到檔案就代表沒擋住**，
> 連線設定會整包外流，必須先修好才能上線。

### 需要開的權限

`storage\logs` 要給 Web Server 帳號（IIS 是 `IIS_IUSRS`）寫入權限，不然寫不了 log。

### 需要的 PHP 擴充

| 擴充 | 用途 |
|---|---|
| `mbstring`、`json` | 必要 |
| `pdo_pgsql` 或 `pgsql` | 連 PostgreSQL |
| `oci8` 或 `pdo_oci` | 連 Oracle |

有沒有載入，開 `/dev/db-check.php` 那一頁就會列出來。

---

## 情境三：接上真實資料庫

跑起來之後照這個順序做：

1. **關掉示範模式** —— `config\app.php` 把 `demo_mode` 改成 `false`
2. **放進公司的 `db.php`** —— 直接覆蓋專案根目錄那一個，不需要改寫成特定格式
3. **開 `/dev/db-check.php`** —— 它會列出 `db.php` 產生了哪些連線變數、各是什麼型別
4. **填 `config\database.php`** 的 `legacy.map`，把變數名填進去：

```php
'map' => [
    'pg'     => ['driver' => 'pgsql',  'var' => 'pgConn'],   // ← 填實際變數名
    'oracle' => ['driver' => 'oracle', 'var' => 'conn'],
],
```

5. **再開一次 `/dev/db-check.php`** —— 兩條連線都要顯示「正常」

接著把 `app\Domain\` 底下 Repository 的 SQL 換成真實的資料表與欄位。
其餘（分頁、排序、匯出、彈窗）都不用動。

**上線前記得**：
- `config\app.php` 的 `demo_mode` 是 `false`、`debug` 是 `false`
- 刪掉 `config\app.php` 的 `demo_users`（測試帳號），改接公司登入邏輯
- 刪掉 `public\dev\` 整個目錄

---

## 接下來

- 想新增功能頁面 → [`HOW-TO.md`](HOW-TO.md) 第 1 節（有產生器，一行生六個檔案）
- 想搬舊頁面進來 → [`HOW-TO.md`](HOW-TO.md) 第 9 節
- 出問題想查原因 → [`HOW-TO.md`](HOW-TO.md) 第 11 節
