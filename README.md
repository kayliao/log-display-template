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
└── Support/                ColumnSet（欄位定義）、Csv（匯入解析）、DateInput（日期欄）、Sql、helpers

config/                  app / database / menu / permission
docs/HOW-TO.md           離線速查手冊
docs/sql/                資料表 DDL 範例（含索引與唯一鍵的理由）
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

解析入口是 `ImportFile::read()`，它**看內容而不是副檔名**決定要用哪個解析器：

| 檔案 | 走哪條路 |
| --- | --- |
| `.xlsx`（開頭是 `PK` 且裡面有 `xl/workbook.xml`） | `Xlsx::read()` |
| 其他 | `Csv::read()` |

看內容是刻意的——現場很習慣直接改副檔名。靠副檔名判斷的話，把 `.xlsx` 改成
`.csv` 傳上來就會走進 CSV 那條路讀出一堆看不懂的東西，這個模板先前就踩過。

#### XLSX：讓編碼問題不存在

支援 `.xlsx` 不只是少按一次「另存新檔」，而是把**整類編碼問題消掉**：

| | CSV / TXT | XLSX |
| --- | --- | --- |
| 編碼 | Big5？UTF-8？UTF-16？要猜 | 一律 UTF-8（ZIP 裡的 XML），無從猜起 |
| 分隔符 | 逗號／Tab／分號要猜 | 沒有分隔符這回事 |
| 日期 | 存的是儲存格「顯示的樣子」，跟著地區設定跑 | 型別化的值，直接轉成 `YYYY-MM-DD` |
| 使用者要做的 | 另存新檔，還要選對編碼 | 直接傳 |

`Xlsx` **零相依**，用內建的 `ZipArchive` + `XMLReader`。沒有用 PhpSpreadsheet：
專案鎖 PHP 7.2，它只能停在 1.17.x（1.18+ 要 7.3+），那是沒有安全更新的分支，
而它處理的正好是使用者上傳的檔案；加上 vendor 會膨脹十幾 MB。

處理掉的細節：共用字串表（`sharedStrings.xml`）、行內字串、**跳號儲存格**
（xlsx 裡空格是「不存在」而不是空字串，不照 `r="C1"` 補位整列會錯位）、
日期序號（含 1900 假閏日與 1904 系統）、自訂日期格式（`0"月"` 這種帶字面量的
不算日期）、布林與公式錯誤值、**照 `workbook.xml` 的順序取第一張工作表**
（工作表被刪過時檔名編號跟畫面順序對不上）。

只做 `.xlsx`。舊的 `.xls` 是 BIFF 二進位格式，自己解析划不來，維持那句
「請另存成 .xlsx 或 CSV」的錯誤訊息。

安全上：`ZipArchive::CHECKCONS` 檢查結構、單一項目解壓後上限 40 MB、
壓縮檔項目數上限 512（擋 zip bomb），XML 一律帶 `LIBXML_NONET`，
libxml 的錯誤收進內部緩衝區而不是變成 PHP warning——那會直接印進 API 回應
把 JSON 弄壞，前端只會看到「回傳格式不對」而不是真正的原因。

#### CSV：處理掉現場最常見的三個坑

`Csv::read()` 處理掉**編碼**、**UTF-8 BOM**、**逗號還是 Tab 分隔**。
所以現場不用先轉檔。

編碼的部分認得四種寫檔方式，涵蓋 Excel 另存新檔與記事本存檔選單裡的每個選項：

| 存檔時選的 | 實際編碼 | 怎麼認出來 |
| --- | --- | --- |
| CSV UTF-8 | UTF-8（含 BOM） | 開頭三個位元組 `EF BB BF`，去掉就好 |
| CSV（逗號分隔） | Big5 / CP950 | 不是合法 UTF-8 也不是 UTF-16，就當 CP950 轉 |
| Unicode 文字 | UTF-16 LE | 開頭 `FF FE`，或空位元組落在奇數位 |
| （少數工具匯出） | UTF-16 BE | 開頭 `FE FF`，或空位元組落在偶數位 |

判斷順序是有講究的，UTF-16 有兩條路要擋，成因還不一樣：

> ⚠ **有 BOM 的 UTF-16** 位元組不是合法 UTF-8，會掉進 Big5 那條路被硬轉成
> 一整片亂碼，例如「目前讀到的欄位是：j盬鋑_?」。
>
> ⚠ **沒有 BOM 的 UTF-16** 更難查。`\0` 本身是合法的 UTF-8 位元組，所以純英文
> 內容的 UTF-16（每個字母後面跟一個 `\0`）會整份通過 `mb_check_encoding()` 而被
> **原封不動放行**，欄位名變成看起來像 `IGEF`、其實是 `I\0G\0E\0F\0` 的東西。
> 畫面上只看得到幾個字母，看不出中間夾了什麼。
>
> 兩條都不會拋錯，所以**空位元組的判斷必須排在 `mb_check_encoding()` 之前**。
> 沒有 BOM 的 UTF-16 靠 `\0` 的位置認：UTF-16 存 ASCII 字元（CSV 一定有的逗號、
> 數字、換行）會固定配一個 `\0`，LE 落在奇數位、BE 落在偶數位。

認 UTF-16 時**不要數空位元組落在奇數位還是偶數位**。UTF-16 存 ASCII 字元時
`\0` 的位置確實固定（LE 落奇數位、BE 落偶數位），但碼位結尾是 `00` 的中文字
剛好相反——**「一」是 U+4E00，在 LE 就是 `00 4E`**。整份檔案只要出現一個
「一」，這種判斷就失效。所以是兩種 UTF-16 都實際轉轉看，再比較轉出來的結果
裡控制字元與未指定碼位（`\p{C}`）的比例，取分數高的那個。

檔案裡 `\0` 佔比超過 5% 又不成 UTF-16 的規律，那就不是文字檔——最常見的是把
`.xls` / `.xlsx` **直接改副檔名**成 `.csv`，會直接擋下並說明原因，不會硬轉成
「`俵遄` 後面接著看不見的 `Workbook`」這種讓人摸不著頭緒的東西。只有零星幾個
`\0`（舊系統匯出的文字檔常拿它當填充）則是清掉繼續處理，那種檔案是好的，只是髒。

#### 匯入檔有問題時先跑這支

```
php tools/check-encoding.php "路徑\匯入檔.csv"
```

它會印出開頭位元組、BOM、`\0` 數量、四種編碼假設下第一行各長什麼樣，以及
`Csv::read()` 實際的結果。現場回報「匯不進去」時附上這段輸出，就不用來回猜。

> 💡 **檔案用記事本開正常、用 Notepad++ 開是亂碼**，代表它是沒有 BOM 的 UTF-16：
> 記事本認得，Notepad++ 會當成 ANSI 逐位元組顯示。這種檔案**匯得進去**。
> 要在 Notepad++ 看正常請選「編碼 → UCS-2 LE」。注意**不要在亂碼狀態下另存**，
> 那會把誤讀的結果存成真的亂碼，反而把好檔案弄壞。

#### 日期欄不要只認一種寫法

Excel 存出去的日期**是那個儲存格當下顯示的樣子**，跟著那台電腦的 Windows
地區設定跑：同一份 xlsx，A 電腦另存出來是 `2026/8/13`，B 電腦可能是 `8/13/2026`，
儲存格被設成「通用格式」的話直接變成 `46247`。三個都是同一天。
匯入端只認一種寫法的話，現場每次上傳前都要先手動改檔。

欄位定義加一個 `normalize`，讀進來就會先轉成 `YYYY-MM-DD` 再驗：

```php
use App\Support\DateInput;

'plan_date' => [
    'title'     => '日期',
    'required'  => true,
    'normalize' => 'date',          // ← 加這一行
    'message'   => DateInput::MESSAGE,
    'sample'    => date('Y-m-d'),
],
```

解析那一列的時候呼叫一次 `applyTo()`，整列的日期欄就都轉好了：

```php
foreach ($columns as $key => $meta) {
    $row[$key] = trim((string) ($raw[$meta['title']] ?? ''));
}

$row = DateInput::applyTo($row, $columns);   // 轉不出來的原樣留著，交給下面的驗證擋
```

驗證時用 `DateInput::problem()` 取代正規表示式（回 `null` 表示沒問題）：

```php
if (($meta['normalize'] ?? '') === 'date') {
    $problem = DateInput::problem($value);

    if ($problem !== null) {
        return $problem;
    }
}
```

| 使用者填的 | 結果 |
|---|---|
| `2026-08-13`　`2026-8-13` | `2026-08-13` |
| `2026/08/13`　`2026.08.13` | `2026-08-13` |
| `20260813` | `2026-08-13` |
| `2026年08月13日` | `2026-08-13` |
| `46247`（Excel 日期序號） | `2026-08-13` |
| `2026/8/13 上午 12:00:00`（儲存格是日期時間） | `2026-08-13`，時間忽略 |
| `２０２６－０８－１３`（全形） | `2026-08-13` |
| `08/13/2026`　`13/08/2026` | **擋下來**：月份和日期分不出哪個是哪個 |
| `26-08-13` | **擋下來**：年份請寫四碼 |
| `2026-02-30` | **擋下來**：沒有這一天 |

> ⚠ 月日順序不明的寫法**故意不猜**。日 ≤ 12 的時候 `8/13` 跟 `13/8` 都是合法日期，
> 程式沒有辦法知道哪個才對；猜錯不會有任何錯誤訊息，只會安靜地存錯一天。
> 水化日期又是機台取號時圈當日範圍用的欄位，存錯一天會牽動封包批號。

`2026-02-30` 這種「格式對、日子不存在」的也一定要在這一層擋掉。只用
`/^\d{4}-\d{2}-\d{2}$/` 驗的話它會過關，一路帶到 Oracle 的 `TO_DATE` 才丟
`ORA-01847`，現場看到的訊息會變成「寫入時被資料庫擋下來」——跟哪一欄填錯完全對不起來。

真的要直接吃 `.xlsx` 是另一件事（要 ZIP + XML 解析器）。這套模板刻意零依賴，
所以走的是「現場另存 CSV，格式由 `DateInput` 吸收」這條路。

**有問題的列要不要擋住整批**，由 `partial` 決定：

```php
View::component('upload', [
    'partial' => true,                                       // 有問題的列只會被跳過，其餘照樣寫入
    'reload'  => 'aquaTable,aquaToday,aquaCycles,aquaAchv',  // 匯完順手重載這幾個元件
]);
```

- `partial => false`（預設）→ 全部沒問題才給按確認。適合「錯一列就代表整份填錯」的主檔。
- `partial => true` → 能寫的先寫進去，寫不進去的由後端回一份**結果報告**，
  前端用彈窗列出「第幾列、哪個批號、為什麼」。現場一次貼上百列時要用這個，
  不然兩列填錯就得整份重傳。

結果報告的格式跟放大鏡彈窗一樣（`{ title, sections }`），所以要多列一段內容是改後端，前端不用動。

**`reload` 可以用逗號分隔多個 id**，表格、達成率統整卡（`achievement`）、
數字小卡（`stat_tile` / `stat_card`）都認得，各模組只挑自己的 id 處理。
畫面上會跟著匯入變的東西全部列進去 —— 水化排程那一頁只刷明細表的話，
右上角「今日統整」會停在匯入前的數字，剛傳上去的那幾筆明明就算今天的，
現場看到數字沒動只會以為檔案沒進去。卡片要另外給 `api` 才重抓得動（見[數字小卡](#數字小卡)）。
表格重載時會留在目前那一頁，不會因為刷新把人踢回第一頁。

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

### 數字小卡

一張卡一個數字，橫著排、寬度只吃自己需要的那麼多，**沒有進度條、沒有比較值**。
給「頁面最上面那排關鍵數字」用：

```php
View::component('stat_tile', [
    'items' => [
        ['label' => '今日產量', 'value' => 15770, 'unit' => '片', 'format' => 'number'],
        ['label' => '達成率',   'value' => 89.6,  'format' => 'percent', 'tone' => 'danger'],
        ['label' => '運轉中',   'value' => 32,    'unit' => '台', 'tone' => 'success',
         'icon' => 'play-circle', 'url' => url('/pages/machine/status.php')],
        ['label' => '目前班別', 'badge' => ['label' => '白班', 'tone' => 'info', 'soft' => true]],
    ],
]);
```

```
┌────────────┐ ┌──────────┐ ┌────────────┐ ┌────────────┐
│ 今日產量    │ │ 達成率    │ │ ▶ 運轉中    │ │ 異常        │
│ 15,770 片   │ │ 89.6%    │ │ 32 台      │ │ 3 台        │
└────────────┘ └──────────┘ └────────────┘ │ A線2台B線1台│
                                            └────────────┘
```

只有一個數字時不用包 `items`：

```php
View::component('stat_tile', ['label' => '最後回報', 'value' => '18:32:34', 'icon' => 'clock-history']);
```

- `url` → 整張卡可以點，但**長相完全一樣**，滑過去才看得出差別
- `min` → 每張卡的最小寬度（預設 148px），數字很長時調大
- `align => 'center'`、`variant => 'plain'`（不要外框，塞進 `panel` 裡面時用）

**要讓數字自己更新**（匯入完、按查詢之後重抓）就多給 `id` 與 `api`：

```php
View::component('stat_tile', [
    'id'    => 'aquaToday',
    'items' => $summary['tiles'],                  // 後端先算好的初始值，一進頁面就有數字
    'api'   => url('/api/hydration/today.php'),
    'field' => 'tiles',                            // 從回應的哪一個鍵取 items（預設 items）
    'auto'  => false,                              // 初始值已在畫面上，載入時不用再打一次
]);
```

之後這個 id 就可以寫進 `upload` 的 `reload`、`filter_bar` 的 `target`，
或直接呼叫 `App.stat.reload('aquaToday')`。重畫的程式在
`public/assets/js/app.stat.js`，**版面刻意跟 PHP 元件寫成一模一樣**——
第一次載入是 PHP 畫的、之後重抓是 JS 畫的，兩邊長得不一樣的話現場會以為數字跳掉了。

`field` 是為了「一個面板好幾張卡」：同一支 API 回一包，`stat_tile` 取 `tiles`、
`stat_card` 取 `cycles`、`achievement` 取 `achv`，前端會合併成**一次**呼叫，
不會把同一組 SQL 跑三次。合併的規則在 `App.http` 的 `shared`
（`public/assets/js/app.http.js`），三個元件走的是同一套，可以混著用。

> 合併只認「網址一樣而且還在跑」，不是快取：上一次跑完了再抓還是會真的去要一次。
> 失敗時訊息只跳一次，但每一張卡都會收到失敗、各自清空自己。

三個很像的元件怎麼選：

| 元件 | 什麼時候用 |
|---|---|
| `stat_tile` | 一張卡一個數字，橫著排。頁面最上面那排關鍵數字 |
| `stat_card` | 一張卡裡好幾個數字（一行一個），可以有進度條與變化量 |
| `achievement` | 預計 vs 實際 vs 達成率，會自己算合計與佔比 |

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

`id` + `api` 的用法跟 `stat_tile` 一樣。重畫時 `title` / `subtitle` 只要回應裡有就一起換掉，
所以**「統計日期」這種會跟著資料變的字要放 `subtitle`**，不要寫死在卡片外面的說明裡：
跨過午夜之後重抓，數字換了日期卻沒換的話，畫面等於在說謊。

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
這個 id 一樣可以寫進 `upload` 的 `reload`，匯完就自己重查。

**要跟數字小卡共用同一支 API 就多給 `field`**，規則跟 `stat_tile` / `stat_card`
一模一樣（見[數字小卡](#數字小卡)），三個元件可以混在同一個面板裡：

```php
// 一支 API 回一包 { tiles: [...], cycles: [...], achv: [...] }，三張卡各取各的
View::component('stat_tile',   ['id' => 'aquaToday',  'field' => 'tiles',  'api' => $api, ...]);
View::component('stat_card',   ['id' => 'aquaCycles', 'field' => 'cycles', 'api' => $api, ...]);
View::component('achievement', ['id' => 'aquaAchv',   'field' => 'achv',   'api' => $api, ...]);
```

三張卡指到同一個網址，重抓時前端只會發出**一次**呼叫。
不給 `field` 就是預設的 `items`，單獨一張卡的頁面完全不用管這個參數。

> ⚠ 合計要讓**資料庫**用 `SUM` 算，不要把明細那一頁加起來。
> 明細是分頁的，前端手上只有當頁資料，加起來會變成「這一頁的合計」。

完整的一頁見 **`/pages/report/schedule.php`（排程達成率）**：
查詢條件列 + 統整卡 + 各線明細表 + CSV 上傳匯入。
那一頁的上傳給了 `'reload' => 'scheduleAchv,scheduleTable'`，
匯完實績卡片與表格一起重查 —— 傳上來的就是這個排程的實績，
達成率不跟著動的話，使用者切回明細看到舊數字會以為檔案沒進去。

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
1100px 以下自動改成上下排，現場的舊螢幕不會被擠爆
（上下排的間距會自動比左右排大 —— 並排時中間有一道空白帶，疊起來就沒有了）。

### 版面節奏

頁面上「一整塊」的元件之間統一留 16px，清單寫在 `app.css` 最前面：

```css
.app-split, .app-filter, .app-table, .app-tabs, .app-achv, .app-statcard { margin-bottom: 16px; }
```

**新做一個整塊型的元件就把 class 加進這一份清單**，不要各自寫 `margin-bottom` ——
漏寫一個就會出現「這兩塊有距離、那兩塊黏在一起」。
塞進 `split` 的欄、`panel` 的內容、頁籤裡面的最後一塊會自動不留下緣空白，
那一層的間距由外層的內距負責。

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
| `achievement` | 達成率統整卡（預計／實際／達成率／合計／佔比） |
| `stat_tile` | 數字小卡，一張卡一個數字、橫著排，沒有進度條 |
| `stat_card` | 重點數字小卡，一張卡裡好幾個數字（一行一個） |
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
`App.achievement`、`App.stat`、`App.session`。

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

一次要送多筆就包成 `{ "items": [ {...}, {...} ] }`（這支最多 500 筆）。單筆與多筆是
`ServiceApi::items()` 統一處理的，順便擋掉兩種請求，都回 422、都不會碰到資料庫：

- **什麼都沒帶**（body 空的、Content-Type 不是 `application/json`、JSON 壞掉）→「沒有可寫入的資料。」
- **超過筆數上限** →「單次最多寫入 500 筆，請分批送出。」

上限是各端點自己拿捏的，取決於那支會把資料庫的鎖持有多久 —— 取封包批號那支只有 50 筆。

另一支 **`POST /service/v1/packet-lot.php`** 是給**機台**打的「取封包批號」：
機台送乾片批號（`ppcup_lot`）進來，本系統產生號碼、寫回水化排程再回傳。
這支示範的是**併發與可重送**（同一個批號重複呼叫拿到同一個號），
完整用法與狀態碼見第 11 節。

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

## 11. 完整範例：水化排程

**`/pages/hydration/schedule.php`（選單：水化管理 → 水化排程）**

這一頁是整套模板裡最完整的一個範例 —— 版面、匯入規則、機台 API、資料表設計
四件事都在裡面，要照著做一頁新的功能，看這一頁就夠。

### 資料表（Oracle 19c）

- **[`docs/sql/aqua_schedule_create.sql`](docs/sql/aqua_schedule_create.sql)** —— 建表用，照著跑就好
- **[`docs/sql/hydration_oracle.sql`](docs/sql/hydration_oracle.sql)** —— 說明版：每個索引與唯一鍵為什麼要有它、上線前檢查清單

| 欄位 | 型別 | 說明 |
|---|---|---|
| `AQUA_SCHEDULE_DATE` | `DATE` | 水化日期（只到日，有 CHECK 擋時分秒） |
| `PPCUP_LOT` | `VARCHAR2(100)` | 乾片批號 |
| `QTY` | `NUMBER(38,0)` | 數量 |
| `PACKET_SCHEDULE_DATE_CODE` | `VARCHAR2(100)` | 封包日編碼，封包批號的中段 |
| `AQUA_CYCLE_NUM` | `NUMBER(38,0)` | 第幾次水化，從 1 開始且必須連號 |
| `PACKET_LOT_TEMP_AUTO` | `VARCHAR2(100)` | 封包批號，**機台來要號時由系統產生後寫回**；最後兩碼是當日順序 |
| `NOTE` | `VARCHAR2(500 CHAR)` | 備註，選填 |
| `UPDATE_USER` | `VARCHAR2(100)` | 最後異動者，NOT NULL |
| `UPDATE_TIME` | `DATE` | 最後異動時間，NOT NULL |

兩個鍵是整頁的地基：

- **主鍵 `(PPCUP_LOT, AQUA_CYCLE_NUM)`** —— 一個乾片批號的一次水化只有一列。
  匯入的「不可以重複」在程式裡先檢查（才有看得懂的訊息），這個鍵是最後一道防線。
  不另外開流水號：沒有子表要參照它，而且匯入的 MERGE 比對鍵剛好就是它
- **唯一鍵 `(PACKET_LOT_TEMP_AUTO)`** —— 取號併發的最後一道防線，
  也擋掉「同一個封包批號被貼到兩列」。Oracle 不索引全 NULL 的鍵，
  所以還沒取號的幾十萬列不會進這個索引

索引也只有兩個：`(AQUA_SCHEDULE_DATE, PPCUP_LOT)` 與
`(PACKET_SCHEDULE_DATE_CODE, SUBSTR(PACKET_LOT_TEMP_AUTO, -2))`。
後者一個索引服務兩件事：「只給封包日編碼查」與「取號時找當天最大號」。

**`UPDATE_USER` 的值一律由外面傳進來**：頁面匯入寫登入者姓名（入口檔從 Session 取，
Domain 不碰 Session）、機台取號寫機台名稱（JSON 帶 `update_user`，沒帶就用 API 金鑰
對應的呼叫端代號）。`NOTE` 用 NULL 不用空字串 —— Oracle 把空字串就當 NULL 存，
「預設空字串」在 Oracle 根本做不到。

資料量以「一天最多一千列」估，一年三十幾萬列 —— 在 Oracle 是小表，
**不需要分割區**，索引多建一兩個的寫入成本也可以忽略。

### 當日順序：從資料算，不用計數表

封包批號的**最後兩碼**就是當日順序，所以下一個號不用另外記帳：

```sql
SELECT MAX(SUBSTR(PACKET_LOT_TEMP_AUTO, -2))
  FROM AQUA_SCHEDULE
 WHERE PACKET_SCHEDULE_DATE_CODE = :date_code
   AND PACKET_LOT_TEMP_AUTO IS NOT NULL
```

字串的 MAX 直接可用，因為編碼是「前一碼 0-9 之後接 A-Z、後一碼 0-9」，
ASCII 裡 `'0'-'9'` 剛好排在 `'A'-'Z'` 前面，字串順序跟數值大小一致（`'99' < 'A0' < 'B2'`）。

**為什麼不用計數表**：計數表會跟真實資料對不起來（有人手動補號、修資料、清幾列），
而且對不起來的時候它照樣發號，發到重複才被唯一鍵擋下；還要多備份一張表、多收統計。
從資料算永遠是單一真相。代價是兩支同時取號會算出同一個號 —— 那正好被唯一鍵擋下，
程式收到 `ORA-00001` 就重算重試（最多五次）。一天最多 120 個號，撞號機率極低。

### 版面：三塊，不用分頁籤

```
┌───────────────────────┬─────────────────────────────┐
│  上傳水化排程          │  今日統整                    │
│  （拖檔 → 驗證 → 匯入）│  （數字小卡 + 分佈 + 取號進度）│
├───────────────────────┴─────────────────────────────┤
│  查詢條件（日期／乾片批號／封包日編碼／封包批號…）      │
│  明細表（可排序、可匯出、點放大鏡看水化歷程）           │
└─────────────────────────────────────────────────────┘
```

整頁就是這幾行（`app/Views/pages/hydration/schedule.php`）：

```php
View::component('split', [
    'ratio' => '1-1',
    'left'  => View::componentHtml('panel', ['title' => '上傳水化排程', 'content' => $upload]),
    'right' => View::componentHtml('panel', ['title' => '今日統整',     'content' => $today]),
]);

View::component('filter_bar', ['id' => 'aquaFilter', 'target' => 'aquaTable', 'fields' => $filters]);
View::component('table',      ['id' => 'aquaTable', 'columns' => $columns,
                               'api' => url('/api/hydration/list.php')]);
```

上半用 `split` 的 `1-1`，1100px 以下自動變上下排。
右上的今日統整看的永遠是「今天」，不跟著下面的查詢條件跑 ——
條件改成上週的話，「今日統整」四個字就不成立了。

**匯入成功之後，今日統整會自己重抓一次。** 上傳元件的
`'reload' => 'aquaTable,aquaToday,aquaCycles,aquaAchv'` 一次點名明細表與那三張卡；
剛匯進去的排程日期就是今天，只刷明細表的話上面的數字會停在匯入前，看起來像沒進去。
逗號分隔的這一串會同時丟給表格、數字小卡、達成率卡三支模組，各自挑自己認得的 id，
不是自己的就跳過 —— 所以觸發端只要寫一行 id 清單，不用管哪個 id 是哪種元件。

三張卡指到同一支 `/api/hydration/today.php`，各取各的 `field`：

| 卡片 | 元件 | `field` | 內容 |
|---|---|---|---|
| `aquaToday` | `stat_tile` | `tiles` | 今日筆數、數量、乾片批號、未取號 |
| `aquaCycles` | `stat_card` | `cycles` | 各次水化的分佈（bar 是佔今日筆數的比例） |
| `aquaAchv` | `achievement` | `achv` | 各次水化的取號進度（預計／實際／達成率） |

前端會合併成一次呼叫，回的就是頁面第一次載入時 PHP 用的那一包 `todaySummary()` ——
兩條路同一個方法，不會出現「重新整理才對得起來」的數字。

> ⚠ `aquaAchv` 的「實際」是拿**機台已經來取過號**當完成，只是為了讓範例有真的會動的數字
> （示範資料裡今天的最後一次水化都還沒取號，所以會看到綠、黃、紅三種達成率）。
> 實務上請換成你自己的實績來源 —— 像 `/pages/report/schedule.php` 那頁就是直接讀
> `mes_schedule_plan.actual_qty`。要換只要改 `HydrationService::todaySummary()` 裡的
> `achv`，元件只認 `plan` / `actual` 兩個鍵，版面與前端一個字都不用動。
> 卡片上的 `target => 60` / `warn => 40` 也是為了示範顏色才壓低的（預設是 100 / 90）。

> ⚠ 示範模式（`demo_mode = true`）不會真的寫入，所以匯入之後重抓回來的還是同一組假數字。
> 想確認它真的有重抓，看瀏覽器 Network 有沒有那一支 `today.php`，或是卡片上閃過的載入遮罩。

檔案分工（要照著做新的一頁，複製這幾個檔就好）：

| 檔案 | 做什麼 |
|---|---|
| `public/pages/hydration/schedule.php` | 入口：驗權限 → 取欄位定義與今日統整 → `View::render()` |
| `app/Views/pages/hydration/schedule.php` | 版面：split + filter_bar + table |
| `app/Views/pages/hydration/_schedule_filters.php` | 查詢條件欄位 |
| `app/Views/pages/hydration/_today.php` | 今日統整（`stat_tile` + `stat_card` + `achievement`，三張卡共用一支 API，匯完自己重抓） |
| `app/Views/pages/hydration/_import_note.php` | 匯入規則說明文案 |
| `public/api/hydration/list.php` | 明細分頁 + CSV 匯出 |
| `public/api/hydration/today.php` | 今日統整（匯入成功後前端重抓的那一支） |
| `public/api/hydration/lot.php` | 放大鏡：一個批號的水化歷程 |
| `public/api/hydration/import.php` | 匯入：template / preview / commit |
| `app/Domain/Hydration/*` | SQL 與規則（Repository = SQL、Service = 規則） |

### 匯入規則：有幾列錯不擋整批

檔案欄位：`水化日期, 數量, 乾片批號, 封包日編碼, 第幾次水化, 備註`
（備註選填；封包批號不在檔案裡，那是機台來要號時才產生的）

水化日期只要年份在前就收，`2026/08/13`、`20260813`、`2026年08月13日`、
Excel 日期序號都可以，寫進資料庫前統一轉成 `YYYY-MM-DD`，
細節見[前面的日期欄那一節](#日期欄不要只認一種寫法)。

| 情況 | 結果 |
|---|---|
| 這個乾片批號還沒有紀錄 | 第幾次水化必須是 1 → 新增 |
| 同一次水化已存在、**還沒取號** | 直接覆蓋（upsert） |
| 同一次水化已存在、**已經有封包批號** | 失敗：號碼已經發給機台，不可以覆蓋 |
| 接在最後一次後面、前一次已取號 | 新增 |
| 跳號、重複、前一次還沒取號 | 失敗，訊息直接寫出「這一筆必須填 N」 |

失敗的那幾列**不會擋住其他列**，匯完會跳一個結果視窗列出「第幾列、哪個批號、為什麼」，
修好那幾列重傳就好。不確定某個批號現在到第幾次，點表格上乾片批號旁邊的放大鏡，
裡面直接寫「下一次要填第幾次」。

### 封包批號怎麼產

```
PACKET_LOT_TEMP_AUTO = PPCUP_LOT 去掉後 5 碼 + PACKET_SCHEDULE_DATE_CODE + 當日順序（2 碼）

PPCUP-A2408-10001  →  PPCUP-A2408-  +  H0812  +  01  =>  PPCUP-A2408-H081201
```

當日順序從 `01` 開始，**兩碼當成一個數字每次加 3**：

```
01 04 07 10 13 16 19 22 25 … 94 97 A0 A3 A6 A9 B2 B5 …
```

十位數 0-9 之後接 A-Z，所以 `A0` = 100、`A9` = 109、`B0` = 110、`Z9` = 359。
規則寫在 `PackLotNumber`，**只有這一個地方**說了算，步進值與字元集在
`config/app.php` 的 `hydration` 可以改。

> ⚠ **兩碼一天最多 120 組**（步進 3）。步進值改成 1 也只有 359 組 —— 兩碼的極限就是 `Z9`。
> 現場估一天最多一千筆，如果每一筆都要一個號就得改成三碼，那會動到號碼長度與格式，
> 要跟機台端、封包端一起確認。先確認的是：那一千筆裡實際會來要號的有幾筆？
> 號碼用完時 API 回 409 並把上限寫在訊息裡，不會默默發出重複號碼。

### 機台 API：取封包批號

**`POST /service/v1/packet-lot.php`** —— 這是唯一一支給機台打的端點。
不吃 Session、用 `X-Api-Key` 驗證，金鑰設在 `config/app.php` 的 `service_api`。

```http
POST /service/v1/packet-lot.php
Content-Type: application/json
X-Api-Key: <金鑰>

{ "ppcup_lot": "PPCUP-A2408-10001" }
```

```json
{
  "ok": true,
  "message": "取號成功",
  "data": {
    "results": [
      { "ppcup_lot": "PPCUP-A2408-10001",
        "packet_lot_temp_auto": "PPCUP-A2408-H081201",
        "aqua_cycle_num": 2,
        "packet_schedule_date_code": "H0812",
        "reused": false }
    ],
    "failed": []
  },
  "trace_id": "..."
}
```

一次要多個號就送 `{ "items": [ { "ppcup_lot": "..." }, ... ] }`（最多 50 筆，一筆一交易）。
上限壓得比機台 Log（500 筆）低很多，是因為取號會鎖住「當日順序」那一列，
一次進來太多筆會把鎖持有太久，其他機台就得排隊。

| 狀態 | 意思 |
|---|---|
| 200 | 取到號。`reused: true` 表示這個號之前就取過了（機台重送） |
| 401 | 金鑰不對 |
| 404 | 這個乾片批號還沒有水化排程資料 |
| 409 | 當天的號碼用完了 |
| 422 | 參數有問題。包含**什麼都沒帶**（「沒有要取號的資料。」）與**超過 50 筆** |
| 503 | 系統忙碌（等鎖逾時），三秒後重試 |

流程（`PackLotService`）：

1. 鎖住那個乾片批號**最新一次水化**那一列（`FOR UPDATE WAIT 3`）
2. **已經有封包批號 → 原號回傳**，不再燒號
3. 鎖住「當日順序」那一列、算出下一個號、把順序往前推
4. 寫回 `PACKET_LOT_TEMP_AUTO`（`WHERE PACKET_LOT_TEMP_AUTO IS NULL`）→ COMMIT

三個關鍵：

- **可以重複呼叫**：機台逾時重送、斷線重試都拿到同一個號。
  少了這一步，重試一次就多一個號，當天的號碼與實際封包數就對不起來
- **同時進來不會撞號**：鎖的是「當天那一列」，同一天排隊、不同天互不影響。
  用 `SELECT MAX(順序)+1` 的話兩支同時算會拿到同一個號
- **交易要短**：`FOR UPDATE` 的鎖持有到 COMMIT，所以這段流程裡沒有任何檔案處理或外部呼叫。
  鎖的順序固定「先水化排程那一列、再當日順序那一列」，反過來會 deadlock

> 為什麼不用 Oracle SEQUENCE：它是全域的、沒辦法每天從 01 重來，
> `NEXTVAL` 也不受交易保護（rollback 之後號碼就是跳掉了）。
> 完整的取捨寫在 [`docs/sql/hydration_oracle.sql`](docs/sql/hydration_oracle.sql) 第 2 節。

---

## 12. 前端改版後的注意事項

改了 `public/assets/` 底下的檔案，記得把 `config/app.php` 的 `version` 往上加一號。
靜態檔網址會帶版本號，不加的話現場瀏覽器會繼續用快取裡的舊檔。
