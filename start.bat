@echo off
chcp 65001 >nul
setlocal enabledelayedexpansion

REM ============================================================================
REM  啟動本機測試伺服器（按兩下這個檔案即可）
REM ============================================================================
REM
REM  它會自動找出這台電腦上的 PHP、啟動內建伺服器、並開啟瀏覽器。
REM  找不到 PHP 時會告訴你可以去哪裡拿。
REM
REM  這只是「看畫面用」的測試伺服器。
REM  正式上線請把專案放進公司現有的 Apache 或 IIS，說明見 docs\START.md。
REM
REM  關閉：在這個黑色視窗按 Ctrl+C，或直接把視窗關掉。
REM ============================================================================

cd /d "%~dp0"

echo.
echo ============================================
echo   廠務機台監看系統 - 本機測試伺服器
echo ============================================
echo.

REM ---- 1. 找 PHP --------------------------------------------------------------
set "PHP="

REM (a) 專案自帶的 php 目錄（把整包 PHP 解壓到這裡就不用另外安裝）
if exist "%~dp0php\php.exe" set "PHP=%~dp0php\php.exe"

REM (b) 系統 PATH
if not defined PHP (
    for /f "delims=" %%i in ('where php 2^>nul') do (
        if not defined PHP set "PHP=%%i"
    )
)

REM (c) 常見的安裝位置
if not defined PHP if exist "C:\xampp\php\php.exe"          set "PHP=C:\xampp\php\php.exe"
if not defined PHP if exist "C:\php\php.exe"                set "PHP=C:\php\php.exe"
if not defined PHP if exist "C:\wamp64\bin\php\php.exe"     set "PHP=C:\wamp64\bin\php\php.exe"
if not defined PHP if exist "C:\Program Files\PHP\php.exe"  set "PHP=C:\Program Files\PHP\php.exe"

REM (d) WAMP / Laragon 的版本子目錄
if not defined PHP (
    for /d %%d in ("C:\wamp64\bin\php\php*") do (
        if not defined PHP if exist "%%d\php.exe" set "PHP=%%d\php.exe"
    )
)
if not defined PHP (
    for /d %%d in ("C:\laragon\bin\php\php*") do (
        if not defined PHP if exist "%%d\php.exe" set "PHP=%%d\php.exe"
    )
)

if not defined PHP goto :nophp

echo   PHP: !PHP!

REM 版本號透過暫存檔取得。直接在 for /f 裡呼叫帶引號的路徑，
REM cmd 的巢狀引號規則會讓命令解析失敗（路徑含空白時尤其明顯）。
set "PHPVER="
"!PHP!" -v > "%TEMP%\_phpver.txt" 2>nul
for /f "tokens=2" %%v in ('type "%TEMP%\_phpver.txt" 2^>nul') do (
    if not defined PHPVER set "PHPVER=%%v"
)
del "%TEMP%\_phpver.txt" >nul 2>&1

if defined PHPVER echo   版本: !PHPVER!
echo.

REM ---- 2. 找一個沒被占用的埠 ---------------------------------------------------
set "PORT="
for %%p in (8099 8100 8101 8102 8103) do (
    if not defined PORT (
        netstat -ano | findstr /r /c:":%%p .*LISTENING" >nul 2>&1
        if errorlevel 1 set "PORT=%%p"
    )
)
if not defined PORT set "PORT=8099"

set "URL=http://127.0.0.1:!PORT!/login.php"

echo   網址: !URL!
echo   帳號: admin    密碼: admin
echo.
echo   ------------------------------------------
echo   要關閉伺服器，請按 Ctrl+C 或關掉這個視窗。
echo   ------------------------------------------
echo.

REM ---- 3. 開瀏覽器並啟動伺服器 -------------------------------------------------
start "" "!URL!"

"!PHP!" -S 127.0.0.1:!PORT! -t public

goto :eof


:nophp
REM 說明文字放在外部檔案用 type 印出，不用 echo 一行一行印。
REM cmd 在 UTF-8 代碼頁下用 echo 輸出中文，長行有機會整行被吃掉；
REM type 直接輸出檔案內容，沒有這個問題。
if exist "%~dp0docs\no-php.txt" (
    type "%~dp0docs\no-php.txt"
) else (
    echo   找不到 php.exe
    echo   see docs\START.md
)
echo.
pause
