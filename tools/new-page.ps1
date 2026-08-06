<#
    新增一個功能頁面（產生器）

    一次產生一整頁需要的六個檔案，並印出要貼進選單的設定。
    這是離線環境下新增功能最快的方式，不用記檔案要放哪、也不用複製貼上改路徑。

    用法：
        powershell -ExecutionPolicy Bypass -File tools\new-page.ps1 `
            -Module report -Name daily -Title "每日生產日報"

    參數：
        -Module   模組代號（英文小寫），決定資料夾名稱，例如 report、machine、log
        -Name     頁面代號（英文小寫），決定檔名，例如 daily、status
        -Title    頁面中文名稱，會顯示在 header 與選單
        -Perm     權限碼，預設是「模組.頁面」，例如 report.daily
        -Force    已存在的檔案也覆蓋（預設會跳過，避免蓋掉你寫好的東西）

    產生的檔案：
        public/pages/<模組>/<頁面>.php              頁面入口
        app/Views/pages/<模組>/<頁面>.php           畫面
        app/Views/pages/<模組>/_<頁面>_filters.php  查詢條件
        public/api/<模組>/<頁面>.php                資料 API
        app/Domain/<模組>/<頁面>Repository.php      SQL
        app/Domain/<模組>/<頁面>Service.php         商業邏輯
#>

param(
    [Parameter(Mandatory = $true)][string]$Module,
    [Parameter(Mandatory = $true)][string]$Name,
    [Parameter(Mandatory = $true)][string]$Title,
    [string]$Perm = "",
    [switch]$Force
)

$ErrorActionPreference = 'Stop'

$root      = Split-Path -Parent $PSScriptRoot
$templates = Join-Path $root 'templates'

$Module = $Module.ToLower()
$Name   = $Name.ToLower()
if ([string]::IsNullOrWhiteSpace($Perm)) { $Perm = "$Module.$Name" }

# 轉成類別名稱用的大駝峰：machine_log -> MachineLog
function ConvertTo-Pascal([string]$text) {
    ($text -split '[_\-\s]' | Where-Object { $_ } | ForEach-Object {
        $_.Substring(0,1).ToUpper() + $_.Substring(1)
    }) -join ''
}

$modulePascal = ConvertTo-Pascal $Module
$namePascal   = ConvertTo-Pascal $Name

# 樣板檔 => 產出位置
$plan = [ordered]@{
    'page.php.stub'       = "public\pages\$Module\$Name.php"
    'view.php.stub'       = "app\Views\pages\$Module\$Name.php"
    'filters.php.stub'    = "app\Views\pages\$Module\_${Name}_filters.php"
    'api.php.stub'        = "public\api\$Module\$Name.php"
    'repository.php.stub' = "app\Domain\$modulePascal\${namePascal}Repository.php"
    'service.php.stub'    = "app\Domain\$modulePascal\${namePascal}Service.php"
}

Write-Host ""
Write-Host "產生「$Title」" -ForegroundColor Cyan
Write-Host ("  模組 $Module / 頁面 $Name / 權限碼 $Perm")
Write-Host ""

$created = 0
$skipped = 0

foreach ($stub in $plan.Keys) {
    $stubPath = Join-Path $templates $stub
    $destPath = Join-Path $root $plan[$stub]

    if (-not (Test-Path $stubPath)) {
        Write-Host ("  找不到樣板 " + $stub) -ForegroundColor Red
        continue
    }

    if ((Test-Path $destPath) -and (-not $Force)) {
        Write-Host ("  已存在，跳過  " + $plan[$stub]) -ForegroundColor DarkGray
        $skipped++
        continue
    }

    $dir = Split-Path -Parent $destPath
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }

    $content = [System.IO.File]::ReadAllText($stubPath, [System.Text.UTF8Encoding]::new($false))

    $content = $content.Replace('{{MODULE_PASCAL}}', $modulePascal)
    $content = $content.Replace('{{NAME_PASCAL}}',   $namePascal)
    $content = $content.Replace('{{MODULE}}',        $Module)
    $content = $content.Replace('{{NAME}}',          $Name)
    $content = $content.Replace('{{TITLE}}',         $Title)
    $content = $content.Replace('{{PERM}}',          $Perm)

    # PHP 檔一律寫成「不帶 BOM 的 UTF-8」，帶 BOM 會讓 PHP 提前送出輸出、害 header() 失效
    [System.IO.File]::WriteAllText($destPath, $content, [System.Text.UTF8Encoding]::new($false))

    Write-Host ("  建立  " + $plan[$stub]) -ForegroundColor Green
    $created++
}

Write-Host ""
Write-Host ("建立 $created 個檔案，跳過 $skipped 個") -ForegroundColor Cyan
Write-Host ""
Write-Host "===== 接下來手動做兩件事 =====" -ForegroundColor Yellow
Write-Host ""
Write-Host "1. 把下面這段加進 config\menu.php 對應的第一層 children 裡："
Write-Host ""
Write-Host "            [" -ForegroundColor White
Write-Host ("                'key'   => '$Perm',") -ForegroundColor White
Write-Host ("                'title' => '$Title',") -ForegroundColor White
Write-Host "                'icon'  => 'table',                 // Bootstrap Icons 名稱，不含 bi- 前綴" -ForegroundColor White
Write-Host ("                'perm'  => '$Perm',") -ForegroundColor White
Write-Host ("                'url'   => '/pages/$Module/$Name.php',") -ForegroundColor White
Write-Host "                'note'  => '這一頁的用途說明。'," -ForegroundColor White
Write-Host "            ]," -ForegroundColor White
Write-Host ""
Write-Host ("2. 把權限碼 '$Perm' 加進 config\permission.php 需要的角色裡。")
Write-Host ("   （角色已經有 '$Module.*' 的話就不用加。）")
Write-Host ""
Write-Host "然後打開這兩個檔案改成實際內容："
Write-Host ("   - app\Domain\$modulePascal\${namePascal}Repository.php   把 SQL 換成真的")
Write-Host ("   - public\pages\$Module\$Name.php                          把欄位定義換成真的")
Write-Host ""
