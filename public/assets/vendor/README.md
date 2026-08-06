# 前端第三方套件（離線）

工廠現場沒有網路，**不可以使用 CDN**。所有套件都要事先下載放進這個目錄，並一起進版控。

在**有網路的電腦**上執行一次即可：

```powershell
powershell -ExecutionPolicy Bypass -File tools\fetch-assets.ps1
```

之後把整個專案（含 `vendor/` 與 `public/assets/vendor/`）複製到現場機器。

---

## 目錄結構

下載完之後應該長這樣。版型 `app/Views/layouts/app.php` 就是照這些路徑載入的，
**如果自行手動下載，路徑與檔名必須完全一致**。

```
public/assets/vendor/
├── bootstrap/
│   ├── bootstrap.min.css
│   └── bootstrap.bundle.min.js
├── bootstrap-icons/
│   ├── bootstrap-icons.css
│   └── fonts/
│       ├── bootstrap-icons.woff
│       └── bootstrap-icons.woff2
├── jquery/
│   └── jquery.min.js
├── datatables/
│   ├── datatables.min.css
│   └── datatables.min.js
└── flatpickr/
    ├── flatpickr.min.css
    ├── flatpickr.min.js
    └── l10n/
        └── zh-tw.js
```

---

## 套件清單與用途

| 套件 | 版本 | 用途 | 為什麼選它 |
|---|---|---|---|
| Bootstrap | 5.3.3 | 基礎樣式、彈窗、下拉選單、分頁籤 | 團隊熟悉度最高，離線只要兩個檔 |
| Bootstrap Icons | 1.11.3 | 全站圖示 | 字型檔在本機，不依賴外部圖示服務 |
| jQuery | 3.7.1 | DataTables 的相依 | 只為了 DataTables，沒有其他用途 |
| DataTables | 2.x (BS5) | 報表排序、分頁、多層表頭 | 中文資料最多、server-side 分頁協定現成 |
| flatpickr | 4.6.13 | 日期區間選擇 | 單檔無相依，容易限制可選範圍 |

> **關於 DataTables**：請下載 [DataTables 官方打包工具](https://datatables.net/download/) 產生的
> `datatables.min.css` / `datatables.min.js`，樣式選 **Bootstrap 5**，
> 套件勾選 **DataTables 核心**即可（Buttons、Select 等目前用不到）。

---

## 手動下載（沒有 PowerShell 或腳本失敗時）

1. Bootstrap — <https://github.com/twbs/bootstrap/releases> 下載 `bootstrap-5.3.3-dist.zip`
   取 `css/bootstrap.min.css` 與 `js/bootstrap.bundle.min.js`
2. Bootstrap Icons — <https://github.com/twbs/icons/releases> 取 `font/` 底下的
   `bootstrap-icons.css` 與 `fonts/` 目錄（**字型檔一定要一起帶，否則圖示全是方塊**）
3. jQuery — <https://code.jquery.com/jquery-3.7.1.min.js>
4. DataTables — <https://datatables.net/download/>（樣式選 Bootstrap 5）
5. flatpickr — <https://github.com/flatpickr/flatpickr/releases> 取 `dist/flatpickr.min.js`、
   `dist/flatpickr.min.css`、`dist/l10n/zh-tw.js`

---

## 升級套件時

1. 在有網路的電腦更新檔案
2. 把 `config/app.php` 的 `version` 往上加一號 —— 靜態檔網址會帶版本號，
   不加的話現場瀏覽器會繼續用舊的快取檔
3. 整包複製到現場，先在一台機器上驗證再全面更新
