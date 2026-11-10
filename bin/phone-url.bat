@echo off
chcp 65001 >nul
setlocal
where php >nul 2>nul
if errorlevel 1 (
  echo php not found in PATH. Edit this file and set PHP=C:\path\to\php.exe
  pause
  exit /b 1
)
php "%~dp0phone-url.php" %*
if errorlevel 1 pause
endlocal