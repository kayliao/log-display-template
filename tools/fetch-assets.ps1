<#
    下載前端第三方套件到 public\assets\vendor\

    在「有網路」的電腦上執行一次即可，之後整包複製到工廠現場。
    現場機器永遠不需要執行這個腳本。

    用法：
        powershell -ExecutionPolicy Bypass -File tools\fetch-assets.ps1

    參數：
        -Force   已存在的檔案也重新下載
#>

param(
    [switch]$Force
)

$ErrorActionPreference = 'Stop'

# 舊版 Windows 預設用 TLS 1.0，會連不上多數 CDN
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$root      = Split-Path -Parent $PSScriptRoot
$vendorDir = Join-Path $root 'public\assets\vendor'

# 版本集中在這裡，升級時只改這一段
$BOOTSTRAP = '5.3.3'
$ICONS     = '1.11.3'
$JQUERY    = '3.7.1'
$FLATPICKR = '4.6.13'

# 目標路徑（相對於 vendor 目錄） => 下載網址
$files = [ordered]@{
    "bootstrap\bootstrap.min.css"            = "https://cdn.jsdelivr.net/npm/bootstrap@$BOOTSTRAP/dist/css/bootstrap.min.css"
    "bootstrap\bootstrap.bundle.min.js"      = "https://cdn.jsdelivr.net/npm/bootstrap@$BOOTSTRAP/dist/js/bootstrap.bundle.min.js"

    "bootstrap-icons\bootstrap-icons.css"    = "https://cdn.jsdelivr.net/npm/bootstrap-icons@$ICONS/font/bootstrap-icons.css"
    "bootstrap-icons\fonts\bootstrap-icons.woff"  = "https://cdn.jsdelivr.net/npm/bootstrap-icons@$ICONS/font/fonts/bootstrap-icons.woff"
    "bootstrap-icons\fonts\bootstrap-icons.woff2" = "https://cdn.jsdelivr.net/npm/bootstrap-icons@$ICONS/font/fonts/bootstrap-icons.woff2"

    "jquery\jquery.min.js"                   = "https://code.jquery.com/jquery-$JQUERY.min.js"

    "flatpickr\flatpickr.min.css"            = "https://cdn.jsdelivr.net/npm/flatpickr@$FLATPICKR/dist/flatpickr.min.css"
    "flatpickr\flatpickr.min.js"             = "https://cdn.jsdelivr.net/npm/flatpickr@$FLATPICKR/dist/flatpickr.min.js"
    "flatpickr\l10n\zh-tw.js"                = "https://cdn.jsdelivr.net/npm/flatpickr@$FLATPICKR/dist/l10n/zh-tw.js"

    # DataTables 官方打包（Bootstrap 5 樣式 + 核心）
    "datatables\datatables.min.css"          = "https://cdn.datatables.net/v/bs5/dt-2.1.8/datatables.min.css"
    "datatables\datatables.min.js"           = "https://cdn.datatables.net/v/bs5/dt-2.1.8/datatables.min.js"
}

Write-Host ""
Write-Host "下載前端套件到：$vendorDir" -ForegroundColor Cyan
Write-Host ""

$ok = 0
$skip = 0
$fail = @()

foreach ($rel in $files.Keys) {
    $dest = Join-Path $vendorDir $rel
    $dir  = Split-Path -Parent $dest

    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }

    if ((Test-Path $dest) -and (-not $Force)) {
        Write-Host ("  略過  {0}" -f $rel) -ForegroundColor DarkGray
        $skip++
        continue
    }

    try {
        Invoke-WebRequest -Uri $files[$rel] -OutFile $dest -UseBasicParsing -TimeoutSec 60
        $size = [math]::Round((Get-Item $dest).Length / 1KB, 1)
        Write-Host ("  完成  {0}  ({1} KB)" -f $rel, $size) -ForegroundColor Green
        $ok++
    }
    catch {
        Write-Host ("  失敗  {0}" -f $rel) -ForegroundColor Red
        Write-Host ("        {0}" -f $_.Exception.Message) -ForegroundColor DarkRed
        $fail += $rel
    }
}

# DataTables 的 CSS 會引用相對路徑的圖片，Bootstrap 5 樣式版本不需要，
# 但若換成其他樣式要記得一併下載對應資源。

Write-Host ""
Write-Host ("完成 {0} 個、略過 {1} 個、失敗 {2} 個" -f $ok, $skip, $fail.Count) -ForegroundColor Cyan

if ($fail.Count -gt 0) {
    Write-Host ""
    Write-Host "以下檔案需要手動下載，網址與說明見 public\assets\vendor\README.md：" -ForegroundColor Yellow
    $fail | ForEach-Object { Write-Host ("  - {0}" -f $_) -ForegroundColor Yellow }
    exit 1
}

Write-Host ""
Write-Host "接下來：" -ForegroundColor Cyan
Write-Host "  1. 執行 composer install（同樣要在有網路的電腦上）"
Write-Host "  2. 確認 vendor\ 與 public\assets\vendor\ 都有進版控"
Write-Host "  3. 整包複製到現場機器"
Write-Host ""
