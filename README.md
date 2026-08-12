# 廠務機台監看系統

工廠機台狀態監看與 Log 查詢系統。原生 PHP + Oracle / PostgreSQL，**離線可運行**。

這是一套「可以慢慢整合舊系統」的模板：舊的 PHP 頁面原封不動放進 `public/legacy/` 就能繼續跑，
一頁一頁改寫，不需要一次全部搬完。

> 兩份離線文件，沒有網路的時候看這兩份就夠：
> - **[`docs/START.md`](docs/START.md)** —— 怎麼跑起來（測試 / Apache / IIS / 接資料庫）
> - **[`docs/HOW-TO.md`](docs/HOW-TO.md)** —— 怎麼改（新增頁面、加放大鏡、搬舊頁面、查問題）

---

## 快速開始

**所有相依都已經在這個 repo 裡了，下載下來直接就能跑，不需要網路、不需要安裝。**

### 按兩下 `start.bat`

它會自動找出這台電腦的 PHP、挑一個沒被占用的埠、啟動伺服器並開啟瀏覽器。

```
帳號 admin   密碼 admin
```

找不到 PHP 時它會把解法印在畫面上（詳見 [`docs/START.md`](docs/START.md)）。

第一次開起來會有黃色的「示範模式」提示列 —— 那是內建的假資料
（48 台機台、260 筆 Log），讓你不必先接資料庫就能看到完整效果。
接上真實資料庫後把 `config/app.php` 的 `demo_mode` 改成 `false` 即可。

### 新增一頁功能

```powershell
powershell -ExecutionPolicy Bypass -File tools\new-page.ps1 -Module report -Name daily -Title "每日生產日報"
```

一次產生頁面、畫面、查詢條件、API、Repository、Service 六個檔案。

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
├── pages/dev/              共用元件目錄（開發參考，上線前可刪）
└── assets/
    ├── vendor/             離線第三方套件
    ├── css/app.css
    └── js/app.*.js

app/                     ← 不對外
├── bootstrap.php           所有入口的第一行
├── Core/                   Db / Auth / Session / View / Request / Response / Upload / Logger …
├── Domain/                 各業務領域的 Repository + Service（SQL 全在這）
├── Views/
│   ├── components/         共用元件，一個檔案一個元件（可以直接改成自己的）
│   ├── layouts/            版型
│   └── pages/              頁面樣板
└── Support/                ColumnSet（欄位定義）、Csv（匯入解析）、Sql、helpers

config/                  app / database / menu / permission
docs/HOW-TO.md           離線速查手冊
templates/               新增頁面用的骨架檔（給 new-page.ps1 用，也可手動複製）
tools/                   new-page.ps1（產生新頁面）、fetch-assets.ps1（下載前端套件）
storage/logs/            應用日誌（依日期切檔，自動清舊檔）
storage/uploads/         匯入檔的暫存區（用完即刪，逾兩小時自動清）
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

> **`/pages/dev/components.php`（選單：開發參考 → 共用元件目錄）**
> 把每個元件的實際長相與對應寫法列在同一頁，要用哪個直接複製走。
> 上線前不想留就刪掉 `public/pages/dev/`，其他功能不受影響。

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
- `children` → 多層表頭，**要幾層就掛幾層**
- `format` → `number` / `decimal` / `percent` / `datetime` / `date` / `status`

**表頭層數不限。** `children` 底下再掛 `children` 就多一層，`colspan` / `rowspan`
由 `ColumnSet` 自己算，深淺不一也沒問題（一支三層、隔壁兩層會自動對齊到底）。
CSV 匯出的標題會串成 `今日產量-白班-良品`，脫離畫面也看得懂。

實際範例見 **`/pages/report/shift.php`（班別產量報表）**：

```
┌──────┬────────────────────────────────────┬───────────┐
│ 機台 │              今日產量               │  全日合計  │
│ 編號 ├─────────────────┬──────────────────┤           │
│      │      白班        │       夜班       │           │
│      ├─────┬────┬──────┼─────┬────┬───────┤ 良品│不良 │
│      │良品 │不良│稼動率│良品 │不良│稼動率 │     │     │
```

### 表單

`field` 是最小的一顆積木，`form` 是它的組合。查詢條件列與編輯表單用的是同一顆元件，
所以 label 字級、必填星號、圖示內距這些細節不會每頁長得不一樣。

```php
View::component('field', ['name' => 'machine_id', 'label' => '機台編號', 'required' => true]);
View::component('field', ['type' => 'select', 'name' => 'area', 'label' => '廠區',
    'empty' => '全部', 'options' => ['A' => 'A 區', 'B' => 'B 區']]);

View::component('form', [
    'columns'  => 2,
    'sections' => [
        ['title' => '基本資料', 'fields' => [
            ['name' => 'machine_id', 'label' => '機台編號', 'required' => true],
            ['type' => 'textarea', 'name' => 'remark', 'label' => '備註', 'span' => 'full'],
        ]],
    ],
    'actions' => [
        ['label' => '取消'],
        ['label' => '儲存', 'variant' => 'primary', 'type' => 'submit'],
    ],
]);
```

`field` 的 `type`：`text` / `number` / `password` / `date` / `textarea` / `select` /
`radio` / `checklist` / `checkbox` / `switch` / `static` / `multi`。
給了 `error` 就自動變紅框並顯示訊息。

### 這些元件都可以改成自己的

元件就是 `app/Views/components/` 底下的**純 PHP 檔**，沒有編譯、沒有註冊表、
沒有繼承關係。要改就直接改，要複製一份改成自己的也可以。三種做法由淺入深：

**A. 直接組合現成的元件**

`form` 吃的是一份陣列，所以「哪些欄位、幾欄、分幾段」是資料不是版面，
可以用程式產生 —— 例如依角色決定哪些欄位唯讀：

```php
$fields = [];

foreach (MachineImportService::columns() as $key => $meta) {
    $fields[] = [
        'name'     => $key,
        'label'    => $meta['title'],
        'required' => !empty($meta['required']),
        'value'    => $row[$key] ?? '',
        'readonly' => !can('machine.edit'),
    ];
}

View::component('form', ['fields' => $fields, 'actions' => [...]]);
```

**B. 把常用的組合包成自己的元件**

同一組欄位在三頁都出現時，存成 `app/Views/components/machine_form.php`：

```php
<?php
use App\Core\View;

$machine = $machine ?? [];
$mode    = $mode ?? 'create';
?>
<?php View::component('form', [
    'columns' => 2,
    'fields'  => [
        ['name' => 'machine_id', 'label' => '機台編號', 'required' => true,
         'value' => $machine['machine_id'] ?? '',
         'readonly' => $mode === 'edit'],          // 編輯時不給改主鍵
    ],
    'actions' => [['label' => '取消'], ['label' => '儲存', 'variant' => 'primary']],
]); ?>
```

之後任何頁面一行叫用：`View::component('machine_form', ['machine' => $row, 'mode' => 'edit']);`

**C. 從零寫一個**

複製一個現有元件當骨架，規則只有三條：

1. 檔案放 `app/Views/components/`，用 `View::component('檔名', [...])` 叫用
2. 開頭先給參數預設值 `$size = $size ?? 88;`（少傳一個參數不該讓元件壞掉）
3. 所有輸出到畫面的變數都要包 `e()`

樣式加在 `public/assets/css/app.css`，class 命名沿用 `app-元件名__部位`，
顏色用 `:root` 的變數不要寫死色碼。要跟前端互動就再開一支
`public/assets/js/app.你的名字.js`，在版型加一行 `<script>`，
照抄 `app.multi.js`（最短的一支，六十行）。

> ⚠ 元件**不會**繼承外層頁面的變數（`View::componentHtml()` 是刻意這樣設計的），
> 要用什麼就明確傳進去。否則頁面的 `$title` 會意外變成表單的標題，這種問題很難查。

逐步說明與更多範例見 `docs/HOW-TO.md` 的
「我要組自己的表單 / 做自己的元件」，畫面上的實際長相見
`/pages/dev/components.php`。

### 一次查很多筆

現場常常要從工單或 Excel 複製一整欄編號來查。`type => multi` 的欄位
逗號、頓號、分號、空白、換行、Tab 都當分隔符號（半形全形都算）：

```php
View::component('field', ['type' => 'multi', 'name' => 'machine_ids',
    'label' => '指定機台', 'limit' => 200]);
```

```php
// API
$filters['machine_ids'] = Request::multi('machine_ids', 200);

// Repository：陣列不能直接塞進具名參數，用 Sql::in() 產生 IN (...)
[$clause, $inBind] = Sql::in('m.machine_id', $filters['machine_ids']);
$sql  .= ' AND ' . $clause;
$bind  = array_merge($bind, $inBind);
```

前端不切字串，原樣送給後端，兩邊只有一套切分規則。

### 檔案上傳匯入

```php
View::component('upload', [
    'api'      => url('/api/machine/import.php'),
    'accept'   => '.csv,.txt',
    'template' => url('/api/machine/import.php?action=template'),
    'columns'  => MachineImportService::columns(),
]);
```

流程固定是兩段：**上傳只做解析與驗證**（列出第幾列、哪一欄、為什麼不行），
使用者確認沒問題才真的寫入，整批包在同一個交易裡。
現場的檔案十次有三次是錯的，一步寫入的話錯誤發生時資料已經進去一半，要人工回頭清。

`Csv::read()` 處理掉現場最常見的三個坑：**Big5 編碼**（Excel 另存的預設）、
**UTF-8 BOM**、**逗號還是 Tab 分隔**。所以現場不用先轉檔。

> ⚠ 這裡沒有用 PHP 內建的 `str_getcsv`。實測 PHP 7.2.24 (Windows NTS x64) 上
> 它遇到中文欄位會把後面的分隔符號一起吃掉——`M-900,新機台,MILL-350,B`
> 會解析成三欄。這種行為隨版本與平台而異，所以 `Csv::parse()` 自己逐位元組解析
> （同時支援引號包住的分隔符與換行）。

寫入的 SQL 在 `MachineImportRepository`，同時列出 **Oracle 的 `MERGE INTO`**
與 **PostgreSQL 的 `INSERT … ON CONFLICT`** 兩種寫法，換資料庫時照著改就好。
範例頁：`/pages/machine/import.php`。

### 一筆資料要直立顯示

表格是「一筆一列」，那是拿來比較很多筆用的。只看一筆的時候改用 `record`：
兩欄等寬、由左至右填，欄位名在左、值在右，跟表格一樣支援大項掛小項。

```php
View::component('record', [
    'title'   => 'M-101　A 線 1 號機',
    'columns' => 2,
    'fields'  => [
        ['label' => '機台編號', 'value' => 'M-101', 'mono' => true],
        ['label' => '目前狀態', 'badge' => ['label' => '運轉中', 'status' => 'run']],

        ['title' => '今日累計', 'children' => [
            ['title' => '產量', 'children' => [
                ['label' => '良品', 'value' => 1280, 'format' => 'number'],
                ['label' => '不良', 'value' => 32,   'format' => 'number'],
            ]],
        ]],

        ['label' => '備註', 'value' => '……', 'span' => 'full'],
    ],
]);
```

**放大鏡彈窗用的是同一套長相。** 後端 Service 回傳
`['type' => 'fields', 'columns' => 2, 'fields' => [...]]`，
前端 `app.modal.js` 會產生跟上面完全一樣的 HTML，兩條路只有一份 CSS 要維護。

### 重點數字小卡

一行一個數字，給「一眼掃過去」用（完整資料請用 `record`）：

```php
View::component('stat_card', [
    'title' => 'M-101 今日概況',
    'items' => [
        ['label' => '稼動率', 'value' => 25, 'unit' => '%',
         'tone' => 'danger', 'bar' => 25, 'delta' => -8.4, 'hint' => '低於目標 75%'],
        ['label' => '良品', 'value' => 1280, 'format' => 'number'],
        ['label' => '目前狀態', 'badge' => ['label' => '運轉中', 'status' => 'run']],
    ],
]);
```

`bar` 會在該列下方畫進度條，`delta` 顯示跟上期的變化（只表示方向，不預設好壞——
不良率上升不是好事）。

### 達成率統整卡

「今天這個排程，預計做多少、實際做多少、達成率多少」——分類明細與合計放在同一張卡上：

```php
View::component('achievement', [
    'title'    => '水化排程達成',
    'subtitle' => '2026-08-12（今日）',
    'unit'     => '片',
    'items'    => [
        ['label' => '白片', 'plan' => 12400, 'actual' => 10590, 'color' => '#0891b2'],
        ['label' => '彩片', 'plan' => 5200,  'actual' => 5180,  'color' => '#7c3aed'],
    ],
]);
```

```
┌────────────────────────────────────────────────────────────┐
│ 水化排程達成                              2026-08-12（今日）│
├────────────────────────────────────────────────────────────┤
│ 總達成率  89.6%                              還差 1,830 片  │
│ ██████████████████████████████████████░░░░                 │
│ 總預計 17,600 片   總實際 15,770 片   差異 -1,830 片        │
│ ████████████████████████████░░░░░░░░░░░░░  白片 67% 彩片 33%│
├──────┬────────┬────────┬────────┬────────┬────────────────┤
│ 項目 │  預計  │  實際  │  差異  │ 達成率 │      佔實際     │
│ 白片 │ 12,400 │ 10,590 │ -1,810 │ 85.4% │      67.2%      │
│ 彩片 │  5,200 │  5,180 │    -20 │ 99.6% │      32.8%      │
└──────┴────────┴────────┴────────┴────────┴────────────────┘
```

**只給 `plan` 與 `actual` 兩個數字，其他都是元件算的**：各項達成率、合計
（Σ預計、Σ實際、總達成率）、每一項佔實際的百分比。
合計自己算一份傳進來的話，遲早會出現「上面幾項加起來不等於下面的合計」，
而且對不起來的時候現場會兩個都不信。

- `target` / `warn` → 顏色門檻。達成率 ≥ 100 綠、≥ 90 黃、再低紅（預設值，可改）
- `share => 'plan'` → 佔比改成看預計而不是看實際
- `summary => 'bottom'` → 合計移到明細下面（預設在上面，一眼先看到總數）
- 預計是 0 的項目不算達成率（不是 0%，也不是無限大），顯示「—」並標成灰色

要跟著查詢條件重查就多給 `api`，並把卡片的 id 一起寫進條件列的 `target`：

```php
View::component('achievement', [
    'id'    => 'scheduleAchv',
    'items' => $summary['items'],                       // 後端先算好的初始值
    'api'   => url('/api/report/schedule_summary.php'),
    'auto'  => false,                                   // 初始值已在畫面上，不用再打一次
]);

View::component('filter_bar', ['target' => 'scheduleAchv,scheduleTable', ...]);
```

按一次查詢，卡片（合計）與表格（明細）一起更新——
兩個數字對不起來是最難跟現場解釋的狀況。

> ⚠ 合計要讓**資料庫**用 `SUM` 算，不要把明細那一頁加起來。
> 明細是分頁的，前端手上只有當頁資料，加起來會變成「這一頁的合計」。

完整的一頁見 **`/pages/report/schedule.php`（排程達成率）**：
查詢條件列 + 統整卡 + 各線明細表 + CSV 上傳匯入。

### 平面圖與分層平面圖

兩種版型都有現成的：

| 頁面 | 長相 |
|---|---|
| `/pages/machine/map.php` | 左邊廠區下拉 + 狀態統計，右邊一張圖，圖跟下拉連動 |
| `/pages/machine/map_floors.php` | 一層樓一個頁籤（2F / 4F），各查各的、**彼此不連動** |

分層版就是把 `machine_map` 放進 `tabs`，每張圖用 `params` 帶自己的樓層：

```php
View::component('machine_map', [
    'id'     => 'floorMap2F',
    'api'    => url('/api/machine/map.php'),
    'axisX'  => range('A', 'H'),      // 每層樓的地面標線不一樣，軸是一層一設
    'axisY'  => range(1, 8),
    'params' => ['floor' => '2F'],    // 這張圖固定只查這一層
    'auto'   => false,                // 切到這個頁籤才查
    // 不給 filter：不跟任何下拉連動
]);
```

- `params` → 每次查詢都帶上的固定參數
- `filter` → 要連動哪一個下拉（CSS 選擇器）。**不給就不連動**
- `auto => false` → 不要一載入就查，等分頁籤切過去才查
  （兩張圖都在 DOM 裡，不延遲的話一進頁面就打兩支 API）

> 樓層清單是從資料庫查出來的（`MachineService::floors()`），多一層樓不用改程式，
> 只要在 `map_floors.php` 的 `$axes` 補上那一層的座標範圍。

### 放大鏡彈窗

彈窗內容由**後端**決定，可以是多段「小標題 + 表格」：

```php
return [
    'title' => 'M-101 詳細資料',
    'sections' => [
        ['type' => 'fields', 'title' => '基本資料', 'fields' => [...]],
        ['type' => 'table',  'title' => '今日分時稼動', 'columns' => [...], 'rows' => [...]],
        ['type' => 'query',  'title' => '歷史 Log 查詢', 'api' => ..., 'fields' => [...]],
    ],
];
```

要多一段內容就改 Service，前端一行都不用動。

四種區塊：

| type | 用途 |
|---|---|
| `fields` | 把一筆資料立起來顯示（兩欄等寬，支援大項掛小項） |
| `table` | 一張表，資料由後端一次算好帶過來 |
| `query` | **可查詢區塊**：有自己的查詢條件，使用者能在彈窗裡改條件重查 |
| `html` | 自由 HTML（要自己逸出） |

`query` 是給「想在彈窗裡看更多、但不想關掉彈窗回列表頁再點一次」的情況：

```php
[
    'type'   => 'query',
    'title'  => '歷史 Log 查詢',
    'api'    => url('/api/machine/history.php'),
    'params' => ['machine_id' => $machineId],   // 每次都帶的固定參數
    'auto'   => true,                           // 開啟彈窗就先查一次
    'fields' => [                               // 條件支援 text / number / date / select
        ['type' => 'date',   'name' => 'start_date', 'label' => '起'],
        ['type' => 'date',   'name' => 'end_date',   'label' => '迄'],
        ['type' => 'select', 'name' => 'event_type', 'label' => '類型', 'empty' => '全部',
         'options' => [['value' => 'ALARM', 'text' => '警報']]],
    ],
    'columns' => [ ['key' => 'log_time', 'title' => '時間', 'format' => 'datetime'], ... ],
]
```

API 回傳 `{ rows: [...] }` 即可。
**後端一樣要用 `Request::dateRange()` 擋區間、並加筆數上限** ——
彈窗的條件是使用者可以改的，跟列表頁的請求一樣不可信任。
範例見 `public/api/machine/history.php`。

### 日期區間

```php
View::component('date_range', ['name' => 'log_date', 'scope' => 'machine_log']);
```

`scope` 對應 `config/app.php` 的 `query_range`。
超出上限的日期在日曆上**直接不能點**，不是選完才跳警告。
後端 `Request::dateRange()` 會再擋一次，避免直接打 API 繞過。

### 版面分欄

「左邊 1/3 放資料、右邊 2/3 放平面圖」這種版型交給 `split`，頁面不用自己刻 grid：

```php
View::component('split', [
    'ratio'  => '1-2',
    'sticky' => 0,                                    // 左欄跟著捲動
    'left'   => View::componentHtml('panel', ['title' => '廠區與狀態', 'content' => $html]),
    'right'  => View::componentHtml('machine_map', ['id' => 'shopMap', 'api' => $api]),
]);
```

比例是 `1-2`、`2-1`、`1-1-1` 這樣寫，三欄以上改給 `panes` 陣列。
1100px 以下自動改成上下排，現場的舊螢幕不會被擠爆。

### 下拉與彈窗按鈕

「按一下會展開東西」的按鈕全站共用同一套長相（header 的主選單、子選單就是這樣做的）：

```php
View::component('dropdown', ['icon' => 'three-dots', 'label' => '更多動作', 'items' => [
    ['title' => '匯出 CSV', 'icon' => 'download', 'attrs' => ['data-role' => 'export-csv']],
    ['divider' => true],
    ['title' => '機台狀態總表', 'icon' => 'list-check', 'url' => '/pages/machine/status.php'],
]]);

View::component('modal_button', ['target' => 'myModal', 'icon' => 'question-circle', 'label' => '欄位說明']);
View::component('modal', ['id' => 'myModal', 'title' => '欄位說明', 'content' => $html]);
```

### 其他

| 元件 | 說明 |
|---|---|
| `achievement` | 達成率統整卡（預計／實際／達成率／合計／佔比），見上一節 |
| `stat_card` | 重點數字小卡，一行一個數字 |
| `announcement` | 公告提醒列，多則自動輪播 |
| `menu_grid` | 功能小卡牆，首頁與 header 主選單彈窗共用 |
| `card` | 單張功能小卡，資料來自 `config/menu.php` |
| `panel` | 白底方框（可有標題列），分欄之後每一欄裝東西用 |
| `tabs` | 分頁籤，`lazy` 的頁籤第一次打開才查資料 |
| `machine_map` | 廠內機台平面圖（原生 SVG，無繪圖套件，含指北針） |
| `compass` | 指北針，角度可設定，見下一節 |
| `filter_bar` | 查詢條件列，按查詢自動重載指定表格 |
| `button` / `button_group` | 按鈕與一排按鈕。有給 `url` 就是連結，但長得一樣 |
| `badge` | 狀態徽章。機台狀態用 `status`、其他用 `tone`，可加 `soft` 變淺色 |
| `empty_state` | 空狀態。把「沒有資料」跟「還沒查詢」講清楚，可放一顆下一步按鈕 |
| `upload` | 檔案上傳匯入（CSV / TXT），兩段式驗證後才寫入 |

### 指北針

廠區的格線是照地面標線畫的，通常不會剛好對正北。指北針是用 SVG 畫的，
**角度是設定值，不是一張圖** —— 改角度只要改一個數字，不用重畫圖再換檔，
放大也不會糊掉，顏色也跟全站配色一致。

```php
// config/app.php
'map' => [
    // 0~360 任意角度，也接受負數與小數。填 -15 跟填 345 是同一件事。
    'north_offset'         => 23.5,      // 北方偏右 23.5 度；偏左填負數
    'compass_position'     => 'bar',     // bar = 放在工具列（預設，不會壓到機台）
                                         // top-right / top-left / bottom-right / bottom-left / none
    'compass_label'        => '廠區座標北',   // 寫清楚是哪一種北，現場才不會誤會
    'compass_angle_format' => 'signed',  // signed = 23.5° E；bearing = 337.5°（0~360）
],
```

量法：拿廠區配置圖或手機指南針，對著平面圖的「往上」方向量。
整廠設定一次，每一張平面圖都會轉到同一個方向；個別頁面要蓋掉就傳 `north`。

指北針預設放在平面圖上方的工具列裡。改成 `top-right` 那類會疊在畫布角落，
好處是離圖比較近，代價是會蓋住那一區的機台 —— 確定那個角落沒有機器再用。

前端 JS：`App.http`、`App.loading`、`App.table`、`App.modal`、`App.dateRange`、`App.machineMap`、
`App.achievement`、`App.session`。

---

## 7. 選單與權限

`config/menu.php` 是**全系統唯一的功能清單**，同時餵給：

- header 的**主選單**（彈窗，列出所有有權限的功能小卡）與**子選單**（下拉，只列目前大項目底下的子功能）
- 首頁的功能小卡（跟主選單彈窗是同一個元件）
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
