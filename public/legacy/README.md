# 舊頁面存放區

尚未改版的 PHP 頁面**原封不動**放在這個目錄，不用改任何一行就能繼續運作。

這是整套架構「可以慢慢整合」的關鍵：新系統上線不需要先把舊頁面全部改寫完。

---

## 放進來之後

1. 把舊的 `.php` 檔（頁面、對應的 ajax、insert 用的檔）整包複製到這裡
2. 舊檔裡的 `require '../db.php'` **不用改** —— 專案根目錄的 `db.php` 還在，
   而且它跟新架構共用同一條連線
3. 在 `config/menu.php` 加上選單項目，`url` 指到這裡，並標記 `'legacy' => true`：

```php
[
    'key'    => 'report.yield',
    'title'  => '良率統計表',
    'icon'   => 'graph-up',
    'perm'   => 'report.yield',
    'url'    => '/legacy/yield_report.php',
    'legacy' => true,          // 選單上會標一個「舊」，讓使用者知道介面不一樣
],
```

這樣舊頁面就會出現在新的主選單與首頁小卡裡，使用者只需要記一個入口。

---

## 讓舊頁面也長出新的 header（選用）

如果希望舊頁面上方也有統一的選單與倒數登出，在舊檔**最上面**加一行：

```php
<?php require __DIR__ . '/_legacy_header.php'; ?>
```

其餘內容完全不用動。`_legacy_header.php` 會輸出 header 並帶入必要的 CSS，
舊頁面自己的樣式仍然有效。

---

## 搬遷順序建議

一頁一頁搬，每搬完一頁就上線驗證，不要一次改一大包。

| 舊檔 | 搬到哪裡 | 做什麼 |
|---|---|---|
| `xxx.php`（頁面） | `public/pages/…` | 只留參數處理與 `View::render()` |
| `xxx_ajax.php` | `public/api/…` | 改回傳標準 JSON 信封 |
| `xxx_insert.php` | `public/service/v1/…` | 若是給別的系統呼叫；若是自己頁面用就放 `api/` |
| 檔案裡的 SQL | `app/Domain/…/XxxRepository.php` | SQL 全部集中，頁面不再出現 SQL |

搬完之後把 `config/menu.php` 的 `url` 改指到新頁面、拿掉 `'legacy' => true`，
再刪掉這裡的舊檔即可。
