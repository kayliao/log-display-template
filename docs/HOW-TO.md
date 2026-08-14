# 離線速查手冊

沒有網路、沒有人可以問的時候看這一份。每一節都是「我要做 X，要動哪些檔案」。

- [1. 我要新增一頁報表](#1-我要新增一頁報表)
- [2. 我要改表格欄位](#2-我要改表格欄位)
- [3. 我要加放大鏡（點欄位跳出詳細資料）](#3-我要加放大鏡點欄位跳出詳細資料)
- [4. 我要加查詢條件](#4-我要加查詢條件)
- [5. 我要限制查詢區間](#5-我要限制查詢區間)
- [6. 我要一頁放多張表（分頁籤）](#6-我要一頁放多張表分頁籤)
- [6.5 我要組自己的表單 / 做自己的元件](#65-我要組自己的表單--做自己的元件)
- [6.8 我要放一張達成率統整卡](#68-我要放一張達成率統整卡)
- [6.9 我要做「上傳 + 統整 + 查詢」三塊一頁](#69-我要做上傳--統整--查詢三塊一頁)
- [7. 我要切版面（左邊資料、右邊放圖）](#7-我要切版面左邊資料右邊放圖)
- [8. 我要加下拉選單、或按一下跳彈窗的按鈕](#8-我要加下拉選單或按一下跳彈窗的按鈕)
- [9. 我要加選單、改權限](#9-我要加選單改權限)
- [10. 我要接舊的 db.php](#10-我要接舊的-dbphp)
- [11. 我要把舊頁面搬進來](#11-我要把舊頁面搬進來)
- [12. 我要開一支給別的系統呼叫的 API](#12-我要開一支給別的系統呼叫的-api)
- [13. 出問題了怎麼查](#13-出問題了怎麼查)
- [14. 常見地雷](#14-常見地雷)

---

## 1. 我要新增一頁報表

**跑產生器，六個檔案一次生好：**

```powershell
powershell -ExecutionPolicy Bypass -File tools\new-page.ps1 -Module report -Name daily -Title "每日生產日報"
```

| 參數 | 說明 | 例 |
|---|---|---|
| `-Module` | 模組代號（英文小寫），決定資料夾 | `report` |
| `-Name` | 頁面代號（英文小寫），決定檔名 | `daily` |
| `-Title` | 中文名稱 | `每日生產日報` |
| `-Perm` | 權限碼，不給就是「模組.頁面」 | `report.daily` |

產生的六個檔案：

```
public/pages/report/daily.php              頁面入口（改欄位定義）
app/Views/pages/report/daily.php           畫面
app/Views/pages/report/_daily_filters.php  查詢條件
public/api/report/daily.php                資料 API
app/Domain/Report/DailyRepository.php      SQL（改這裡）
app/Domain/Report/DailyService.php         商業邏輯
```

**然後做三件事：**

1. 把產生器印出來的那段設定貼進 `config/menu.php`
2. 權限碼加進 `config/permission.php` 的角色裡（角色已有 `report.*` 就不用）
3. 改 `DailyRepository.php` 的 SQL 和 `daily.php` 的欄位定義

> 不想用產生器就從 `templates/` 手動複製，裡面的 `{{MODULE}}`、`{{NAME}}` 自己換掉。

---

## 2. 我要改表格欄位

只改**頁面入口**（`public/pages/<模組>/<頁面>.php`）的 `$columns`。
表頭、排序白名單、CSV 匯出欄位會自動跟著變，不用改三個地方。

```php
$columns = [
    // 一般欄位
    ['key' => 'machine_id', 'title' => '機台編號', 'width' => 120],

    // 標題出現問號，滑鼠移上去顯示說明
    ['key' => 'oee', 'title' => '稼動率', 'tip' => '運轉 ÷（運轉+待機+停機）'],

    // 數字靠右加千分位
    ['key' => 'qty', 'title' => '數量', 'align' => 'right', 'format' => 'number'],

    // 大標底下掛小標（兩層表頭）
    ['title' => '今日產量', 'children' => [
        ['key' => 'qty_ok', 'title' => '良品', 'align' => 'right', 'format' => 'number'],
        ['key' => 'qty_ng', 'title' => '不良', 'align' => 'right', 'format' => 'number'],
    ]],

    // 要幾層就掛幾層，colspan / rowspan 自動算（範例：/pages/report/shift.php）
    ['title' => '今日產量', 'children' => [
        ['title' => '白班', 'children' => [
            ['key' => 'd_ok', 'title' => '良品', 'align' => 'right', 'format' => 'number'],
            ['key' => 'd_ng', 'title' => '不良', 'align' => 'right', 'format' => 'number'],
        ]],
        ['title' => '夜班', 'children' => [
            ['key' => 'n_ok', 'title' => '良品', 'align' => 'right', 'format' => 'number'],
            ['key' => 'n_ng', 'title' => '不良', 'align' => 'right', 'format' => 'number'],
        ]],
    ]],

    // 預設隱藏，但使用者可以切換顯示
    ['key' => 'remark', 'title' => '備註', 'visible' => false],

    // 這一欄不給排序
    ['key' => 'memo', 'title' => '說明', 'sortable' => false],
];
```

`format` 可用值：

| 值 | 效果 |
|---|---|
| `number` | 1,234 |
| `decimal` | 12.34 |
| `percent` | 85.0% |
| `datetime` | 2026-08-06 13:45:00 |
| `date` | 2026-08-06 |
| `status` | 彩色徽章（需要 row 裡有 `status_label`） |

**加了新欄位但排序點了沒反應？** 檢查 API 檔裡的 `$sortable` 陣列有沒有把欄位加進去。

---

## 3. 我要加放大鏡（點欄位跳出詳細資料）

**第一步**，在欄位定義加 `drill`：

```php
['key' => 'machine_id', 'title' => '機台', 'drill' => [
    'api'    => url('/api/machine/detail.php'),
    'params' => ['machine_id'],        // 要從該列帶哪些欄位當參數
]],
```

多個參數就多寫幾個：`'params' => ['machine_id', 'log_time']`。

**第二步**，開一支 API 回傳彈窗內容。彈窗可以有很多段，
每段是「欄位清單」或「表格」，段數隨你：

```php
return [
    'title'    => 'M-101 詳細資料',
    'sections' => [
        ['type' => 'fields', 'title' => '基本資料', 'fields' => [
            ['label' => '機台編號', 'value' => $row['machine_id']],
            ['label' => '狀態',     'value' => '運轉中', 'badge' => 'run'],
        ]],

        ['type' => 'table', 'title' => '今日分時稼動',
         'columns' => [
             ['key' => 'hour_label', 'title' => '時段'],
             ['key' => 'qty_ok',     'title' => '良品', 'align' => 'right'],
         ],
         'rows' => $this->repo->todayHourly($id)],

        // 想再加一張表就再加一段，前端不用改
        ['type' => 'table', 'title' => '近期異常', 'columns' => [...], 'rows' => [...]],
    ],
];
```

彈窗長什麼樣完全由後端決定，**JavaScript 一行都不用碰**。
可以參考 `app/Domain/Machine/MachineService.php` 的 `detail()`。

`type => fields` 就是「把一筆資料立起來顯示」：兩欄等寬、由左至右填，
欄位名在左、值在右。要幾欄給 `columns`，要分段用 `children`：

```php
['type' => 'fields', 'title' => '基本資料', 'columns' => 2, 'fields' => [
    ['label' => '機台編號', 'value' => $row['machine_id'], 'mono' => true],
    ['label' => '狀態',     'value' => '運轉中', 'badge' => 'run'],

    ['title' => '今日累計', 'children' => [           // 大項底下掛小項
        ['label' => '良品', 'value' => 1280, 'format' => 'number'],
        ['label' => '不良', 'value' => 32,   'format' => 'number'],
    ]],

    ['label' => '備註', 'value' => $row['remark'], 'span' => 'full'],
]],
```

同樣的東西要直接畫在頁面上（不透過彈窗）就用 `record` 元件，
參數一樣、長相一樣：

```php
View::component('record', ['title' => 'M-101', 'columns' => 2, 'fields' => [...]]);
```

### 彈窗裡要能自己查資料

`type => table` 是後端一次算好送過來，看完就沒了。
如果要讓使用者在彈窗裡改條件重查（不用關掉彈窗回列表頁再點一次），
用 `type => query`：

```php
[
    'type'    => 'query',
    'title'   => '歷史 Log 查詢',
    'api'     => url('/api/machine/history.php'),
    'params'  => ['machine_id' => $machineId],   // 每次都帶的固定參數
    'auto'    => true,                           // 開啟彈窗就先查一次
    'empty'   => '這段期間沒有 Log 記錄。',
    'fields'  => [                               // 查詢條件
        ['type' => 'date',   'name' => 'start_date', 'label' => '起', 'value' => date('Y-m-d', strtotime('-6 days'))],
        ['type' => 'date',   'name' => 'end_date',   'label' => '迄', 'value' => date('Y-m-d')],
        ['type' => 'select', 'name' => 'event_type', 'label' => '類型', 'empty' => '全部',
         'options' => [['value' => 'ALARM', 'text' => '警報'], ['value' => 'ERROR', 'text' => '錯誤']]],
    ],
    'columns' => [
        ['key' => 'log_time', 'title' => '時間', 'width' => 150, 'format' => 'datetime'],
        ['key' => 'message',  'title' => '訊息'],
    ],
],
```

條件欄位支援 `text` / `number` / `date` / `select`。
彈窗裡的條件本來就該少，需要更複雜的查詢請走獨立頁面。

對應的 API 回傳 `{ rows: [...] }` 就好，欄位定義已經寫在 section 裡，兩邊不用各維護一份。
**日期區間一樣要用 `Request::dateRange()` 擋**——彈窗的條件是使用者可以改的，
改完就是一支普通的 API 請求，沒有比列表頁「更值得信任」。
也記得加筆數上限（範例是 200），不然有人把區間拉滿又剛好挑到一台狂噴警報的機器，
瀏覽器會直接卡死。範例見 `public/api/machine/history.php`。

---

## 4. 我要加查詢條件

改 `app/Views/pages/<模組>/_<頁面>_filters.php`，加一個欄位：

```php
<div class="app-field">
    <label class="app-field__label" for="f_shift">班別</label>
    <select class="form-select" id="f_shift" name="shift">
        <option value="">全部</option>
        <option value="D">日班</option>
        <option value="N">夜班</option>
    </select>
</div>
```

然後在 API 檔接起來（`name` 要一致）：

```php
$filters['shift'] = Request::str('shift');
```

再到 Repository 加條件：

```php
if (!empty($filters['shift'])) {
    $sql .= " AND t.shift = :shift";
    $bind['shift'] = $filters['shift'];
}
```

**就這三步。** 前端的「按查詢就重新載入表格」是自動的，不用寫 JavaScript。

---

## 5. 我要限制查詢區間

在 `config/app.php` 的 `query_range` 定一個名字：

```php
'query_range' => [
    'machine_log' => 7,     // 最多一週
    'report'      => 31,    // 最多一個月
    'default'     => 31,
],
```

**前端**（日曆上直接不給點超過範圍的日期）：

```php
View::component('date_range', [
    'name'  => 'log_date',
    'scope' => 'machine_log',
]);
```

**後端**（防止直接打 API 繞過）：

```php
[$start, $end] = Request::dateRange('log_date_start', 'log_date_end', 'machine_log');
```

兩邊用同一個 `scope`，改一個數字兩邊同時生效。

---

## 6. 我要一頁放多張表（分頁籤）

```php
use App\Core\View;

$tab1 = View::componentHtml('table', ['id' => 'tableA', 'columns' => $colsA, 'api' => url('/api/x/a.php')]);
$tab2 = View::componentHtml('table', ['id' => 'tableB', 'columns' => $colsB, 'api' => url('/api/x/b.php'), 'auto' => false]);

View::component('tabs', [
    'id'   => 'myTabs',
    'tabs' => [
        ['key' => 'a', 'title' => '明細', 'icon' => 'list-ul', 'content' => $tab1],
        ['key' => 'b', 'title' => '統計', 'icon' => 'pie-chart', 'lazy' => true, 'content' => $tab2],
    ],
]);
```

> `lazy => true` 搭配表格的 `auto => false`：切到那個頁籤才去查資料。
> 不設的話一進頁面就同時打好幾支 API，資料庫會很痛苦。

**表格的 `auto` 跟查詢條件列的關係**：

| 情況 | 一進頁面的行為 |
|---|---|
| 有查詢條件列、`auto => true`（預設） | 等條件列把預設條件交給表格，**帶著條件**查一次 |
| 有查詢條件列、`auto => false` | 只收下條件不查，等使用者按「查詢」 |
| 沒有查詢條件列、`auto => true` | 直接查一次 |

表格不會搶在條件列前面自己打一次沒帶條件的 API——
那次請求會因為缺少必填的日期區間被後端擋下，使用者一進頁面就看到紅色錯誤訊息。

一個查詢條件列要同時更新兩張表，`target` 用逗號分隔：

```php
View::component('filter_bar', ['target' => 'tableA,tableB', 'fields' => $fields]);
```

完整例子看 `app/Views/pages/log/machine.php`。

---

## 6.5 我要組自己的表單 / 做自己的元件

**可以，而且這就是預期的用法。** 元件就是 `app/Views/components/` 底下的純 PHP 檔，
沒有編譯、沒有註冊表、沒有繼承關係。三種做法由淺入深：

### A. 直接組合現成的元件（最常用）

`form` 吃的是一份陣列，所以「哪些欄位、幾欄、分幾段」都是資料，不是版面：

```php
View::component('form', [
    'columns'  => 2,
    'sections' => [
        ['title' => '機台資料', 'fields' => [
            ['name' => 'machine_id', 'label' => '機台編號', 'required' => true],
            ['type' => 'select', 'name' => 'area', 'label' => '廠區', 'options' => ['A', 'B', 'C']],
            ['type' => 'multi', 'name' => 'tags', 'label' => '關聯編號', 'span' => 'full'],
        ]],
        ['title' => '保養設定', 'fields' => [ /* ... */ ]],
    ],
    'actions' => [
        ['label' => '取消'],
        ['label' => '儲存', 'variant' => 'primary', 'type' => 'submit'],
    ],
]);
```

欄位清單是普通陣列，所以可以用程式產生 —— 例如從欄位定義、從資料庫的欄位表、
或依角色決定哪些欄位要唯讀：

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

### B. 把常用的組合包成自己的元件

同一組欄位在三頁都出現時，就把它存成一個檔案。
新增 `app/Views/components/machine_form.php`：

```php
<?php
/**
 * 機台基本資料表單。
 * 新增與編輯共用，差別只在有沒有帶 machine。
 */

use App\Core\View;

$machine = $machine ?? [];
$mode    = $mode ?? 'create';
?>
<?php View::component('form', [
    'id'      => 'machineForm',
    'columns' => 2,
    'fields'  => [
        ['name' => 'machine_id', 'label' => '機台編號', 'required' => true,
         'value' => $machine['machine_id'] ?? '',
         'readonly' => $mode === 'edit'],           // 編輯時不給改主鍵
        ['name' => 'machine_name', 'label' => '機台名稱', 'required' => true,
         'value' => $machine['machine_name'] ?? ''],
    ],
    'actions' => [
        ['label' => '取消'],
        ['label' => $mode === 'edit' ? '儲存' : '新增', 'variant' => 'primary', 'type' => 'submit'],
    ],
]); ?>
```

之後任何頁面一行叫用：

```php
View::component('machine_form', ['machine' => $row, 'mode' => 'edit']);
```

> 元件**不會**繼承外層頁面的變數（`View::componentHtml()` 是刻意這樣設計的），
> 所以要用什麼就明確傳進去。這樣頁面的 `$title` 才不會意外變成表單的標題。

### C. 從零寫一個新元件

複製一個現有的元件當骨架就好，規則只有三條：

1. 檔案放 `app/Views/components/你的名字.php`，用 `View::component('你的名字', [...])` 叫用
2. 開頭先把參數補上預設值：`$size = $size ?? 88;`（元件不該因為少傳一個參數就壞掉）
3. 所有輸出到畫面的變數都要包 `e()`

樣式加在 `public/assets/css/app.css`，class 命名沿用 `app-元件名__部位`，
顏色用 `:root` 的變數不要寫死色碼 —— 這樣之後換配色只要改一個地方。
改完記得把 `config/app.php` 的 `version` 加一號。

要跟前端互動就再開一支 `public/assets/js/app.你的名字.js`，
在版型 `app/Views/layouts/app.php` 加一行 `<script>`，寫法照抄 `app.multi.js`
（最短的一支，六十行）。

---

## 6.8 我要放一張達成率統整卡

「今天這個排程，預計做多少、實際做多少、達成率多少」——
分類明細與合計放在同一張卡上，用 `achievement` 元件。

**只給 `plan` 與 `actual` 兩個數字，其他都是元件算的：**

```php
View::component('achievement', [
    'title'    => '水化排程達成',
    'subtitle' => date('Y-m-d') . '（今日）',
    'unit'     => '片',
    'items'    => [
        ['label' => '白片', 'plan' => 12400, 'actual' => 10590],
        ['label' => '彩片', 'plan' => 5200,  'actual' => 5180],
    ],
]);
```

| 元件會自己算 | 怎麼算 |
|---|---|
| 各項達成率 | 實際 ÷ 預計（預計 0 就顯示「—」，不算成 0% 也不算成無限大） |
| 合計 | Σ預計、Σ實際、Σ實際 ÷ Σ預計 |
| 佔比 | 該項實際 ÷ Σ實際（`share => 'plan'` 可改成看預計） |
| 顏色 | 達成率 ≥ `target`（預設 100）綠、≥ `warn`（預設 90）黃、再低紅 |

合計**不要自己算好傳進來**。兩份數字遲早會對不起來，
而且對不起來的時候現場會兩個都不信。

**要跟著查詢條件重查就多給 `api`：**

```php
// 頁面：先在後端算好初始值，一進頁面就有數字可看
View::component('achievement', [
    'id'    => 'scheduleAchv',
    'items' => $summary['items'],
    'api'   => url('/api/report/schedule_summary.php'),
    'auto'  => false,     // 初始值已經畫出來了，不用再自動打一次 API
]);

// 查詢條件列把卡片與表格一起指定，按一次查詢兩邊同時更新
View::component('filter_bar', ['target' => 'scheduleAchv,scheduleTable', ...]);
```

API 回傳 `{ items: [{ label, plan, actual, color? }], title?, subtitle?, footer? }` 即可，
前端 `app.achievement.js` 會畫出跟 PHP 一模一樣的結構。

> ⚠ 合計要讓**資料庫**用 `SUM` 算，不要把明細那一頁加起來——
> 明細是分頁的，前端手上只有當頁資料，加起來會變成「這一頁的合計」。
> 範例見 `ScheduleRepository::summary()`。

完整的一頁（條件列 + 統整卡 + 明細表 + 上傳匯入）見
**`/pages/report/schedule.php`（排程達成率）**。

---

## 6.9 我要做「上傳 + 統整 + 查詢」三塊一頁

照抄 **`/pages/hydration/schedule.php`（水化排程）**，那是這套模板裡最完整的一頁。

版面刻意不用分頁籤 —— 上半左右各一半，下半整片是資料：

```php
View::component('split', [
    'ratio' => '1-1',
    'left'  => View::componentHtml('panel', ['title' => '上傳', 'content' => $upload]),
    'right' => View::componentHtml('panel', ['title' => '今日統整', 'content' => $today]),
]);

View::component('filter_bar', ['id' => 'hydFilter', 'target' => 'hydTable', 'fields' => ...]);
View::component('table',      ['id' => 'hydTable', ...]);
```

四件會踩到的事：

**1. 統整要不要跟著查詢條件跑？**
「今日統整」就是今天，不要跟著下面的日期區間變 —— 條件改成上週的話那四個字就不成立了。
反過來，如果卡片標題是「本次查詢統計」，那就要跟著跑（做法見
[6.8 達成率統整卡](#68-我要放一張達成率統整卡) 的 `api` + `filter_bar` 的 `target`）。

**2. 有幾列填錯要不要擋住整批？**
現場一次貼上百列時不要擋：

```php
View::component('upload', [
    'partial' => true,          // 有問題的列只會被跳過
    'reload'  => 'hydTable',    // 匯完順手重載表格
]);
```

後端的 commit 回傳裡多帶一個 `report`（格式跟放大鏡彈窗一樣），
前端就會自動用彈窗列出「第幾列、哪個批號、為什麼沒進去」：

```php
return [
    'written' => 3, 'insert' => 1, 'update' => 2, 'failed' => 5,
    'report'  => ['title' => '匯入完成（有 5 筆沒進去）', 'sections' => [...]],
];
```

**3. 錯誤訊息要寫「所以我該填什麼」。**
「順序錯誤」現場還是不知道要改成幾；要寫成
「目前已經到第 1 次，這一筆必須填 2（目前填的是 9）」。

**4. 日期欄不要只認一種寫法。**
Excel 存 CSV 時寫出去的是儲存格顯示的樣子，跟著那台電腦的地區設定跑，
所以同一份檔案在不同電腦上可能是 `2026/8/13`、`8/13/2026` 或 `46247`。
欄位定義加 `'normalize' => 'date'`，`DateInput` 會統一轉成 `YYYY-MM-DD`：

```php
use App\Support\DateInput;

'plan_date' => [
    'title'     => '日期',
    'required'  => true,
    'normalize' => 'date',
    'message'   => DateInput::MESSAGE,
    'sample'    => date('Y-m-d'),
],
```

解析每一列時多呼叫一次 `applyTo()`，驗證時用 `problem()` 代替正規表示式：

```php
$row = DateInput::applyTo($row, $columns);        // 讀進來就先轉

// validateCell 裡
if (($meta['normalize'] ?? '') === 'date') {
    $problem = DateInput::problem($value);        // 回 null 表示沒問題
    if ($problem !== null) {
        return $problem;
    }
}
```

⚠ **兩個地方都要轉**：預覽跟真正寫入如果各自解析檔案（例如
`ScheduleImportService` 的 `preview()` 與 `validRows()`），兩邊都要呼叫 `applyTo()`。
只轉一邊的話，比對鍵裡的日期長得不一樣，會變成預覽說「更新」、實際卻插了一筆新的。

`08/13/2026` 這種月日順序不明的會被擋下來，這是故意的 —— 猜錯不會報錯，
只會安靜地存錯一天。完整的接受清單見 README 的「日期欄不要只認一種寫法」。

---

## 7. 我要切版面（左邊資料、右邊放圖）

用 `split` 元件，不要自己在頁面刻 CSS grid——刻五頁就會有五種間距。

```php
use App\Core\View;

View::component('split', [
    'ratio'  => '1-2',    // 左邊 1 份、右邊 2 份（也就是 1/3 和 2/3）
    'sticky' => 0,        // 第 0 欄跟著捲動，右邊的圖再長，篩選都還看得到

    'left'  => View::componentHtml('panel', [
        'title'   => '廠區與狀態',
        'icon'    => 'sliders',
        'content' => View::capture('pages/machine/_map_side', ['areas' => $areas]),
    ]),

    'right' => View::componentHtml('machine_map', [
        'id'  => 'shopMap',
        'api' => url('/api/machine/map.php'),
    ]),
]);
```

| 參數 | 說明 |
|---|---|
| `ratio` | 欄寬比例，`-` 分隔。`1-1`、`1-2`、`2-1`、`1-3`、`1-2-1` 都行 |
| `left` / `right` | 兩欄的內容 HTML |
| `panes` | 三欄以上用這個（陣列），比例份數要跟欄數一樣多 |
| `sticky` | 哪一欄跟著捲動，`0` = 第一欄 |
| `gap` | 欄距（px），預設 16 |
| `stack` | `false` = 窄螢幕也不換成上下排 |

**1100px 以下會自動變成上下排**，現場那些 1366 寬的舊螢幕不會被擠爆。

每一欄裡面請包一層 `panel`，才跟表格、平面圖是同一套外框：

```php
View::componentHtml('panel', [
    'title'   => '查詢條件',
    'icon'    => 'funnel',
    'tools'   => '<button class="btn btn-sm btn-outline-secondary">重設</button>',
    'content' => $html,
    'flush'   => true,     // 內容不留內距，要塞滿版表格時用
]);
```

完整例子看 `app/Views/pages/machine/map.php`。

---

## 8. 我要加下拉選單、或按一下跳彈窗的按鈕

三個元件，湊起來就是 header 上那兩顆按鈕的做法。

### 下拉選單

```php
View::component('dropdown', [
    'icon'  => 'three-dots',
    'label' => '更多動作',
    'align' => 'end',            // 按鈕在畫面右側時，選單靠右對齊
    'items' => [
        ['title' => '匯出 CSV', 'icon' => 'download', 'attrs' => ['data-role' => 'export-csv']],
        ['divider' => true],
        ['title' => '機台狀態總表', 'icon' => 'list-check', 'url' => '/pages/machine/status.php'],
    ],
]);
```

- 選項給了 `url` 就是連結，沒給就是按鈕（行為自己用 `data-role` 綁）
- `['divider' => true]` 分隔線、`['header' => '分組名']` 小標題
- `'active' => true` 標成目前所在

### 按一下跳彈窗

彈窗本體與按鈕分開寫：

```php
// 按鈕
View::component('modal_button', [
    'target' => 'myModal',
    'icon'   => 'question-circle',
    'label'  => '欄位說明',
]);

// 彈窗本體
View::component('modal', [
    'id'         => 'myModal',
    'title'      => '欄位說明',
    'icon'       => 'question-circle',
    'size'       => 'lg',        // sm | lg | xl | fullscreen
    'scrollable' => true,
    'content'    => $html,
    'footer'     => false,       // false = 不要頁尾；不傳 = 一顆「關閉」
]);
```

> ⚠ 要放在 **header** 上的按鈕，彈窗本體一定要寫在 `app/Views/partials/overlays.php`，
> 不能留在 header 裡。header 是 sticky 而且有 z-index，自成一個堆疊層，
> 彈窗留在裡面會被 Bootstrap 掛在 body 上的遮罩蓋住，變成點不到。

內容是查了 API 才知道的（放大鏡那種），不要用這幾個元件，用 `App.modal.detail()`
——見 [3. 我要加放大鏡](#3-我要加放大鏡點欄位跳出詳細資料)。

---

## 9. 我要加選單、改權限

### 選單

只改 `config/menu.php`。**header 的主選單與子選單、首頁小卡、權限檢查會同時生效。**

header 上只有兩顆按鈕，內容都是從這份設定長出來的：

| 按鈕 | 點下去 | 內容 |
|---|---|---|
| 主選單 | 跳出彈窗 | 使用者**所有**有權限的功能，一個大項目一張小卡（跟首頁同一個元件） |
| 子選單 | 往下展開 | **目前這個大項目**底下的子功能，例如人在「報表」就只列報表的東西 |

不在任何選單項目上的頁面（例如首頁）不會有子選單，這是正常的。

```php
[
    'key'   => 'report',
    'title' => '報表',
    'icon'  => 'bar-chart-line',      // Bootstrap Icons 名稱，不含 bi- 前綴
    'perm'  => 'report.view',
    'children' => [
        [
            'key'   => 'report.daily',
            'title' => '每日生產日報',
            'icon'  => 'calendar-day',
            'perm'  => 'report.daily',
            'url'   => '/pages/report/daily.php',
            'note'  => '會顯示在 header 的「程式說明」裡。',
        ],
    ],
],
```

圖示名稱到 `public/assets/vendor/bootstrap-icons/bootstrap-icons.css` 裡搜，
或直接沿用現有頁面的。

### 權限

改 `config/permission.php`：

```php
'roles' => [
    'ADMIN'    => ['*'],                        // 全部
    'MANAGER'  => ['report.*', 'log.*'],        // report 開頭全部
    'OPERATOR' => ['monitor.view', 'log.machine'],
],
```

**沒權限的功能使用者連看都看不到**（選單和小卡都不會出現），不是點了才被擋。

### 改成讀資料庫的權限表

1. 改 `config/permission.php` 的 `db` 區塊，填上實際的資料表與欄位名
2. 若 SQL 結構跟預設假設不同，改 `app/Core/Permission/DbPermissionProvider.php` 的 SQL
3. 把 `'provider'` 從 `'config'` 改成 `'db'`

其他地方一行都不用改。

---

## 10. 我要接舊的 db.php

1. 把公司的 `db.php` 覆蓋掉專案根目錄的那個檔（**不需要改寫成特定格式**）
2. 用瀏覽器開 `/dev/db-check.php`
3. 看它列出 `db.php` 產生了哪些變數、各是什麼型別
4. 把變數名填進 `config/database.php`：

```php
'legacy' => [
    'enabled' => true,
    'file'    => BASE_PATH . '/db.php',
    'map' => [
        'pg' => [
            'driver' => 'pgsql',
            'var'    => 'pgConn',       // ← 填 db.php 裡的變數名
        ],
        'oracle' => [
            'driver' => 'oracle',
            'var'    => 'conn',
        ],
    ],
],
```

`db.php` 是用函式提供連線的話改成：

```php
'oracle' => ['driver' => 'oracle', 'function' => 'getOracleConnection'],
```

**支援的型別**：PDO 物件、`oci_connect()` 的 resource、`pg_connect()` 的 resource，都會自動包成統一介面。

完成後再開一次 `/dev/db-check.php`，兩條連線都應該顯示「正常」。
**業務程式碼一行都不用改。**

---

## 11. 我要把舊頁面搬進來

### 先讓它跑起來（五分鐘）

1. 舊的 `.php` 整包丟進 `public/legacy/`
2. 舊檔裡的 `require '../db.php'` **不用改**
3. `config/menu.php` 加一筆，`url` 指過去，加上 `'legacy' => true`（選單會標一個「舊」字）

### 讓舊頁面也有新 header（選用）

舊檔**最上面**加一行，其他都不動：

```php
<?php require __DIR__ . '/_legacy_header.php'; ?>
```

### 之後慢慢改寫

一頁一頁搬，每搬完一頁就驗證，不要一次改一大包。

| 舊檔 | 搬到 |
|---|---|
| `xxx.php`（頁面） | `public/pages/…` |
| `xxx_ajax.php` | `public/api/…` |
| `xxx_insert.php` | 別的系統呼叫 → `public/service/v1/…`；自己頁面用 → `public/api/…` |
| 檔案裡的 SQL | `app/Domain/…/XxxRepository.php` |

改完把選單的 `url` 改指新頁面、拿掉 `'legacy' => true`、刪掉舊檔。

---

## 12. 我要開一支給別的系統呼叫的 API

放在 `public/service/v1/`。跟前端 API 完全分開：不吃 Session、用金鑰驗證、每次呼叫都記錄。

```php
<?php
define('APP_API_ENTRY', true);
define('APP_NO_SESSION', true);

require dirname(__DIR__, 3) . '/app/bootstrap.php';

use App\Core\Request;
use App\Core\ServiceApi;

ServiceApi::requireMethod('POST');
$client  = ServiceApi::authenticate();      // 驗金鑰，失敗直接回 401
$payload = Request::json();

ServiceApi::requireFields($payload, ['machine_id', 'value']);

// ...寫入資料...

ServiceApi::success(['id' => 123], '寫入成功');
```

金鑰設在 `config/app.php`（正式環境請放 `config/local.php`）：

```php
'service_api' => [
    'keys' => [
        'MES'   => '這裡放金鑰字串',
        'SCADA' => '這裡放金鑰字串',
    ],
    'ip_whitelist' => ['10.20.0.0/16'],   // 空陣列 = 不限制
],
```

呼叫端要帶 `X-Api-Key` 標頭。完整例子看 `public/service/v1/machine-log.php`
（含多筆寫入與整批交易）。

---

## 13. 出問題了怎麼查

### 現場說「壞掉了」

畫面上會有一串**代碼**（`trace_id`），叫他報給你。
到 `storage/logs/app-YYYY-MM-DD.log` 搜那串代碼，就是那次請求的完整錯誤。

### 查詢很慢

超過 2 秒的查詢會自動記進 log，關鍵字搜「慢查詢」。
門檻在 `config/database.php` 的 `slow_query_ms`。

### 想看每一句 SQL

`config/database.php` 把 `log_queries` 改成 `true`。
**很吃硬碟，查完記得關掉。**

### 想看完整錯誤訊息（不是「請聯絡資訊人員」）

`config/local.php` 加：

```php
return ['app' => ['debug' => true]];
```

**正式環境務必關掉**，開著會把檔案路徑洩漏給使用者。

### 對外 API 出問題

`storage/logs/api-YYYY-MM-DD.log` 有每一筆呼叫的來源、參數、結果。

---

## 14. 常見地雷

| 症狀 | 原因 | 解法 |
|---|---|---|
| 改了 CSS/JS 但畫面沒變 | 瀏覽器快取 | `config/app.php` 的 `version` 加一號 |
| 圖示全是方塊 | `bootstrap-icons/fonts/` 沒帶到 | 把整個 `fonts/` 目錄補齊 |
| PowerShell 腳本一堆亂碼錯誤 | `.ps1` 沒有 UTF-8 BOM | 用 VSCode 存成「UTF-8 with BOM」 |
| PHP 檔存成 UTF-8 BOM 後壞掉 | BOM 會提前送出輸出，害 `header()` 失效 | PHP 檔一律存**不帶 BOM** 的 UTF-8 |
| log 用記事本開是亂碼 | 編碼問題 | 系統寫檔時已加 BOM；舊檔請用 VSCode 開 |
| 排序點了沒反應 | 欄位不在後端白名單 | API 檔的 `$sortable` 補上欄位名 |
| Oracle 查詢欄位名讀不到 | Oracle 回傳大寫欄位名 | 系統已統一轉小寫，用小寫取值 |
| 分頁怪怪的 | SQL 自己寫了 LIMIT | 不要寫，交給 `Paginator` |
| 表格一直轉圈 | API 回傳格式不對 | 一律用 `Response::page()` / `Response::ok()` |
| 頁面顯示 403 | 角色沒有那個權限碼 | 改 `config/permission.php` |
| 新頁面 404 | 選單 `url` 跟實際檔案位置不符 | 對一下 `config/menu.php` |
| 查詢條件列的 label 高低不齊 | 某個欄位比隔壁高（例如底下多一列快捷鍵） | 不用管，條件列是頂端對齊 + label 固定高度，本來就會齊；真的歪掉先看是不是有人改了 `.app-filter` 的 `align-items` |
| 寫了 DataTables 的樣式卻沒生效 | class 名稱是 1.x 的 | 本專案是 **2.1.8**：`.dataTables_length` → `.dt-length`、`.dataTables_info` → `.dt-info`、`.dataTables_paginate` → `.dt-paging`、`.dataTables_wrapper` → `.dt-container`。寫錯不會報錯，只是安靜地沒有效果 |
| 表格下方的「每頁 N 筆」貼著左邊緣 | DataTables 2 的版面用 Bootstrap `.row`，它有 -12px 的負 margin 會吃掉內距 | 已在 `.app-table .dt-container > .row` 抵消掉，不要移除那段 |
| 中文 CSV 匯入後欄位錯位 | PHP 內建的 `str_getcsv` 在部分版本會把中文後面的分隔符吃掉 | 用 `App\Support\Csv::read()`，不要直接呼叫 `str_getcsv` |
| 匯入說「缺少必要欄位」，而且列出來的欄位名是亂碼 | 檔案是 **UTF-16**。存檔時選了記事本的「Unicode」或 Excel 的「Unicode 文字 (*.txt)」 | `Csv::read()` 已經認得 UTF-16，更新後直接重傳即可。想確認檔案編碼看下面兩列 |
| 欄位名看起來是幾個正常的英文字母（例如 `IGEF`），但就是對不到 | 一樣是 **UTF-16**，只是內容是英文所以不會變成亂碼。字母中間夾了看不見的 `\0`，畫面上完全看不出來 | 同上。這種檔案在舊版會通過 UTF-8 檢查被原封不動放行，是最難查的一種 |
| 使用者手上是 Excel 檔，不想每次都另存成 CSV | 不用了 | 直接上傳 `.xlsx`。`ImportFile::read()` 看內容判斷格式，xlsx 沒有編碼問題，日期也是型別化的值 |
| 上傳 `.xls` 被擋下來 | 舊的 BIFF 二進位格式，系統不解析 | 用 Excel 另存成 `.xlsx` 或 CSV。只有 `.xls` 需要這一步 |
| 匯入說「這個檔案是壓縮檔，不是可以匯入的表格」 | 傳到的是 `.zip` 或其他非 xlsx 的壓縮檔 | 先解開再傳裡面的檔案 |
| 匯入說「這個檔案不是文字檔」 | `.xls` / `.xlsx` 直接改副檔名成 `.csv`。Excel 活頁簿是二進位檔，不是文字 | 在 Excel 用「另存新檔」選「CSV UTF-8（逗號分隔）」重存，不要改副檔名 |
| 檔案用記事本開正常，用 Notepad++ 開卻是亂碼 | 檔案是**沒有 BOM 的 UTF-16**。記事本認得（它有內建偵測），Notepad++ 會當成 ANSI 逐位元組顯示 | 這種檔案匯得進去。要在 Notepad++ 看正常，選「編碼 → UCS-2 LE」；要轉檔就接著選「編碼 → 轉為 UTF-8」再存。**不要在亂碼狀態下另存**，那會把誤讀的結果存成真的亂碼 |
| 想知道手上的檔案到底是什麼編碼 | 記事本狀態列寫的是 ANSI/UTF-8/UTF-16 LE，但存檔選單的「Unicode」不會標明是 UTF-16 | 用 PowerShell 看開頭四個位元組：`Format-Hex -Path 檔案.csv -Count 4`。`EF BB BF` 是 UTF-8 BOM、`FF FE` 是 UTF-16 LE、`FE FF` 是 UTF-16 BE、都不是就是 UTF-8 或 Big5 |
| 同一份檔案在別台電腦匯入就說日期格式錯 | Excel 存 CSV 寫的是儲存格顯示的樣子，跟著那台電腦的 Windows 地區設定跑 | 欄位定義加 `'normalize' => 'date'`，由 `App\Support\DateInput` 統一轉成 `YYYY-MM-DD` |
| 日期欄整欄變成 `46247` 這種五位數 | 儲存格格式被改成「通用格式」，存出來就是 Excel 的日期序號 | 不用處理，`DateInput` 認得序號 |
| 匯入報「寫入時被資料庫擋下來」 | 日期只用 `/^\d{4}-\d{2}-\d{2}$/` 驗，`2026-02-30` 會過關，到 Oracle 的 `TO_DATE` 才丟 ORA-01847 | 日期欄一律用 `DateInput::problem()` 驗，不要自己寫正規表示式 |

---

## 附：本機測試

**按兩下專案根目錄的 `start.bat`** —— 它會自動找出這台電腦的 PHP、
啟動伺服器並開啟瀏覽器。測試帳號 `admin` / `admin`。

找不到 PHP、或要正式放進 Apache / IIS，見 [`START.md`](START.md)。

> 測試帳號設在 `config/app.php` 的 `demo_users`，
> **接上公司登入邏輯後請刪掉這一段。**
