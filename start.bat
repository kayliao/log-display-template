@echo off
chcp 65001 >nul
setlocal enabledelayedexpansion

REM ============================================================================
REM  Start the local test server. Just double-click this file.
REM
REM  IMPORTANT - this file must stay 100%% ASCII.
REM  cmd.exe parses a .bat byte-by-byte in the OEM codepage, so CJK characters
REM  written directly in here get split apart and executed as commands,
REM  even inside REM comments and even after chcp 65001.
REM  All Chinese text lives in docs\*.txt and is printed with `type`,
REM  which handles multi-byte characters correctly.
REM ============================================================================

cd /d "%~dp0"

echo.
echo ============================================
echo   Factory Machine Monitor - Local Server
echo ============================================
echo.

REM ---- 1. Locate PHP ----------------------------------------------------------
set "PHP="

REM (a) PHP shipped inside the project (unzip a portable PHP into .\php)
if exist "%~dp0php\php.exe" set "PHP=%~dp0php\php.exe"

REM (b) PATH
if not defined PHP (
    for /f "delims=" %%i in ('where php 2^>nul') do (
        if not defined PHP set "PHP=%%i"
    )
)

REM (c) Common install locations
if not defined PHP if exist "C:\xampp\php\php.exe"          set "PHP=C:\xampp\php\php.exe"
if not defined PHP if exist "C:\php\php.exe"                set "PHP=C:\php\php.exe"
if not defined PHP if exist "C:\wamp64\bin\php\php.exe"     set "PHP=C:\wamp64\bin\php\php.exe"
if not defined PHP if exist "C:\Program Files\PHP\php.exe"  set "PHP=C:\Program Files\PHP\php.exe"

REM (d) Versioned subfolders used by WAMP / Laragon
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

REM ---- 2. PHP version ---------------------------------------------------------
REM Via a temp file: calling a quoted path inside for /f breaks cmd's
REM nested-quote parsing when the path contains spaces.
set "PHPVER="
"!PHP!" -v > "%TEMP%\_phpver.txt" 2>nul
for /f "tokens=2" %%v in ('type "%TEMP%\_phpver.txt" 2^>nul') do (
    if not defined PHPVER set "PHPVER=%%v"
)
del "%TEMP%\_phpver.txt" >nul 2>&1

REM ---- 3. Pick a free port ----------------------------------------------------
set "PORT="
for %%p in (8099 8100 8101 8102 8103) do (
    if not defined PORT (
        netstat -ano | findstr /r /c:":%%p .*LISTENING" >nul 2>&1
        if errorlevel 1 set "PORT=%%p"
    )
)
if not defined PORT set "PORT=8099"

set "URL=http://127.0.0.1:!PORT!/login.php"

echo   PHP     : !PHP!
if defined PHPVER echo   Version : !PHPVER!
echo   URL     : !URL!
echo   Login   : admin / admin
echo.

if exist "%~dp0docs\start-info.txt" type "%~dp0docs\start-info.txt"
echo.

REM ---- 4. Open browser and run -------------------------------------------------
start "" "!URL!"

"!PHP!" -S 127.0.0.1:!PORT! -t public

goto :eof


:nophp
if exist "%~dp0docs\no-php.txt" (
    type "%~dp0docs\no-php.txt"
) else (
    echo   php.exe not found. See docs\START.md
)
echo.
pause
