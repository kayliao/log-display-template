# 廠務機台監看系統

工廠機台狀態監看與 Log 查詢系統。原生 PHP + Oracle / PostgreSQL，**離線可運行**。

這是一套「可以慢慢整合舊系統」的模板：舊的 PHP 頁面原封不動放進 `public/legacy/` 就能繼續跑，
一頁一頁改寫，不需要一次全部搬完。

> **沒有網路的時候看 [`docs/HOW-TO.md`](docs/HOW-TO.md)** —— 離線速查手冊，
> 「我要新增一頁 / 加放大鏡 / 接舊 db.php / 出問題怎麼查」全部有步驟。

---

## 快速開始

**所有相依都已經在這個 repo 裡了，下載下來直接就能跑，不需要網路。**

```powershell
php -S 127.0.0.1:8099 -t public
```

瀏覽器開 <http://127.0.0.1:8099/login.php>，測試帳號 `admin` / `admin`。

新增一頁：

```powershell
powershell -ExecutionPolicy Bypass -File tools\new-page.ps1 -Module report -Name daily -Title "每日生產日報"
```

---

## 1. 環境需求

| 項目 | 需求 |
|---|---|
| PHP | **7.2 以上**（已在 7.2.24 完整實測） |
| 必要擴充 | `mbstring`、`json` |
| PostgreSQL | `pdo_pgsql` 或 `pgsql` |
| Oracle | `oci8`（建議）或 `pdo_oci` |
| Web Server | Apache 或 IIS |

> 現場沒有網路。所有第三方套件（`vendor/` 與 `public/assets/vendor/`）**都已經進版控**，
> 現場機器永遠不執行 `composer install`，也不從 CDN 載入任何東西。

---

## 2. 安裝

**直接把整個 repo 下載/複製到機器上就能用。** `vendor/` 與 `public/assets/vendor/`
（Bootstrap、Bootstrap Icons、jQuery、DataTables、flatpickr）都已經含在裡面。

只有在**升級套件版本**時才需要有網路的電腦：

```powershell
composer install
powershell -ExecutionPolicy Bypass -File tools\fetch-assets.ps1
```

### Web Server 設定

兩種都支援，建議用 A：

**A. DocumentRoot 指到 `public/`**（安全性較好）
`app/`、`config/`、`vendor/`、`storage/` 完全無法從瀏覽器存取。
根目錄的 `index.php`、`.htaccess`、`web.config` 可以刪掉。

**B. DocumentRoot 指到專案根目錄**（不用改 vhost）
由根目錄的 `index.php` 轉發，`.htaccess`（Apache）或 `web.config`（IIS）負責擋住非公開目錄。
**用這個方式時務必確認擋檔規則有生效** —— 用瀏覽器試開 `/config/database.php`，
應該要是 403 而不是下載到檔案。

### 本機設定

複製一份 `config/local.php`（不進版控），覆寫該機器專屬的設定：

```php
<?php
return [
    'app' => [
        'debug' => true,
    ],
    'database' => [
        'connections' => [
            'pg' => ['host' => '10.0.0.20', 'username' => 'xxx', 'password' => 'xxx'],
        ],
    ],
];
```

`storage/logs` 要開寫入權限。

---

## 3. 目錄結構

```
public/                  ← 唯一對外的目錄
├── index.php               首頁（依權限產生功能小卡）
├── login.php  logout.php   登入 / 登出
├── pages/                  前端頁面，一頁一檔
├── api/                    前端 ajax 用的 API（Session 驗證）
├── service/v1/             給別的系統呼叫的純後端 API（API 金鑰驗證）
├── legacy/                 尚未改版的舊頁面
├── dev/db-check.php        連線診斷頁（上線前可刪）
└── assets/
    ├── vendor/             離線第三方套件
    ├── css/app.css
    └── js/app.*.js

app/                     ← 不對外
├── bootstrap.php           所有入口的第一行
├── Core/                   Db / Auth / Session / View / Request / Response / Logger …
├── Domain/                 各業務領域的 Repository + Service（SQL 全在這）
├── Views/                  版型、共用元件、頁面樣板
└── Support/                輔助函式、欄位定義

config/                  app / database / menu / permission
docs/HOW-TO.md           離線速查手冊
templates/               新增頁面用的骨架檔（給 new-page.ps1 用，也可手動複製）
tools/                   new-page.ps1（產生新頁面）、fetch-assets.ps1（下載前端套件）
storage/logs/            應用日誌（依日期切檔，自動清舊檔）
vendor/                  Composer 產出，進版控
db.php                   既有連線檔（相容層）
```

---

## 4. 分層規則

只有三條，但要嚴格遵守：

1. **入口檔不寫 SQL、不寫 HTML。**
   頁面與 API 只負責：驗證權限 → 整理參數 → 呼叫 Service → 輸出。
2. **SQL 只出現在 `app/Domain/*/XxxRepository.php`。**
   之後要改資料表、加索引、換資料庫，只需要看這一層。
3. **HTML 只出現在 `app/Views/`。**

一頁的標準長相：

```php
<?php
require __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\View;

Auth::requirePermission('monitor.status');

View::render('pages/machine/status', [
    'title'   => '機台狀態總表',
    'columns' => $columns,
]);
```

一支 API 的標準長相：

```php
<?php
require __DIR__ . '/../_boot.php';

use App\Core\Auth;
use App\Core\Request;
use App\Core\TableQuery;

Auth::requirePermission('monitor.status');

$query  = TableQuery::fromRequest($sortable, 'machine_id', 'asc');
$result = (new MachineService())->statusTable($filters, $query);

$query->respond($result);
```

---

## 5. 資料庫

### 連線沿用既有的 `db.php`

`App\Core\Db\LegacyBridge` 會載入專案根目錄的 `db.php`，
自動辨識它建立的是 PDO、`oci8` resource 還是 `pgsql` resource，包成統一介面。
所以**連線帳密只有一個地方維護**，舊頁面與新頁面用的是同一條連線。

接上實際的 `db.php` 之後：

1. 開 `/dev/db-check.php`，看它列出 `db.php` 產生了哪些變數
2. 把正確的變數名填進 `config/database.php` 的 `legacy.map`
3. 完成，業務程式碼一行都不用改

### 兩種資料庫共用同一份程式碼

```php
Db::pg()->select($sql, $bind);        // PostgreSQL
Db::oracle()->select($sql, $bind);    // Oracle
```

參數一律用具名參數 `:name`（兩種資料庫都支援）。
欄位名回傳時統一轉小寫（Oracle 預設回大寫，不統一的話同一份樣板換個資料庫就全壞）。

### 後端分頁

`Paginator` 吸收方言差異，Repository 只要寫一句沒有 `LIMIT` 的 SQL：

```php
$result = $query->paginate(Db::oracle(), $sql, $bind);
// PostgreSQL => LIMIT n OFFSET m
// Oracle 12c => OFFSET m ROWS FETCH NEXT n ROWS ONLY
// Oracle 11g => ROWNUM 巢狀子查詢
```

排序欄位必須在白名單內，白名單由欄位定義自動產生，
所以「畫面上能點的」和「後端允許的」永遠一致。

---

## 6. 共用元件

### 報表表格

一份欄位定義同時決定表頭、前端行為與 CSV 匯出欄位：

```php
$columns = [
    ['key' => 'machine_id', 'title' => '機台編號',
     'drill' => ['api' => url('/api/machine/detail.php'), 'params' => ['machine_id']]],

    ['key' => 'oee', 'title' => '稼動率', 'format' => 'percent',
     'tip' => '運轉時間 ÷（運轉 + 待機 + 停機）'],

    ['title' => '今日產量', 'children' => [        // 大標底下掛小標
        ['key' => 'qty_ok', 'title' => '良品', 'align' => 'right', 'format' => 'number'],
        ['key' => 'qty_ng', 'title' => '不良', 'align' => 'right', 'format' => 'number'],
    ]],
];

View::component('table', [
    'id' => 'statusTable', 'columns' => $columns,
    'api' => url('/api/machine/list.php'),
    'export' => url('/api/machine/list.php?export=csv'),
]);
```

- `tip` → 欄位標題出現問號，滑鼠移上去顯示說明
- `drill` → 欄位出現放大鏡，點下去打 API，結果顯示在彈窗
- `children` → 兩層表頭
- `format` → `number` / `decimal` / `percent` / `datetime` / `date` / `status`

### 放大鏡彈窗

彈窗內容由**後端**決定，可以是多段「小標題 + 表格」：

```php
return [
    'title' => 'M-101 詳細資料',
    'sections' => [
        ['type' => 'fields', 'title' => '基本資料', 'fields' => [...]],
        ['type' => 'table',  'title' => '今日分時稼動', 'columns' => [...], 'rows' => [...]],
        ['type' => 'table',  'title' => '近期異常',     'columns' => [...], 'rows' => [...]],
    ],
];
```

要多一段內容就改 Service，前端一行都不用動。

### 日期區間

```php
View::component('date_range', ['name' => 'log_date', 'scope' => 'machine_log']);
```

`scope` 對應 `config/app.php` 的 `query_range`。
超出上限的日期在日曆上**直接不能點**，不是選完才跳警告。
後端 `Request::dateRange()` 會再擋一次，避免直接打 API 繞過。

### 其他

| 元件 | 說明 |
|---|---|
| `announcement` | 公告提醒列，多則自動輪播 |
| `card` | 首頁功能小卡，資料來自 `config/menu.php` |
| `tabs` | 分頁籤，`lazy` 的頁籤第一次打開才查資料 |
| `machine_map` | 廠內機台平面圖（原生 SVG，無繪圖套件） |
| `filter_bar` | 查詢條件列，按查詢自動重載指定表格 |

前端 JS：`App.http`、`App.loading`、`App.table`、`App.modal`、`App.dateRange`、`App.machineMap`、`App.session`。

---

## 7. 選單與權限

`config/menu.php` 是**全系統唯一的功能清單**，同時餵給：

- header 的主選單 / 子選單
- 首頁的功能小卡
- 權限檢查

新增功能只要改這一個檔案，三個地方同時生效。

權限目前讀 `config/permission.php`。要改成讀公司既有的權限資料表時：
實作 `App\Core\Permission\DbPermissionProvider`（骨架已備好），
把 `provider` 改成 `'db'`，呼叫端完全不用改。

---

## 8. 對外 API

給 MES / SCADA 之類的系統呼叫，跟前端頁面完全分開：不吃 Session、用 API 金鑰驗證、每次呼叫都記錄。

```
POST /service/v1/machine-log.php
X-Api-Key: <金鑰>
Content-Type: application/json

{ "machine_id":"M-101", "log_time":"2026-08-06 13:45:00",
  "event_code":"AL-102", "event_type":"ALARM", "message":"主軸溫度過高" }
```

金鑰與 IP 白名單設在 `config/app.php` 的 `service_api`（正式環境請寫在 `config/local.php`）。

---

## 9. 錯誤與日誌

- 現場永遠不會看到 PHP 白畫面或路徑外洩的錯誤訊息
- 頁面請求 → 乾淨的錯誤頁；API 請求 → 標準 JSON 錯誤信封
- 兩者都附 `trace_id`，跟 `storage/logs` 裡的記錄對得起來 —— **現場報修時報這串就能查**
- 慢查詢（預設超過 2 秒）會自動記錄，方便抓效能問題

`AppException` 的訊息會**原樣顯示給使用者**（例如「查詢區間最多 7 天」），
其他例外只寫進 log，畫面上只顯示通用訊息。使用者操作錯誤丟前者，程式壞掉丟後者。

---

## 10. 遷移舊系統

1. 舊 PHP 整包丟 `public/legacy/`，在 `config/menu.php` 加選單並標 `'legacy' => true`
2. 舊檔的 `require '../db.php'` 不用改
3. 想讓舊頁面也長出新 header：舊檔最上面加一行 `require __DIR__ . '/_legacy_header.php';`
4. 一頁一頁改寫：頁面 → `pages/`、ajax → `api/`、insert → `service/v1/`、SQL → Repository
5. 改完把選單的 `url` 指到新頁面，刪掉舊檔

詳見 `public/legacy/README.md`。

---

## 11. 前端改版後的注意事項

改了 `public/assets/` 底下的檔案，記得把 `config/app.php` 的 `version` 往上加一號。
靜態檔網址會帶版本號，不加的話現場瀏覽器會繼續用快取裡的舊檔。
