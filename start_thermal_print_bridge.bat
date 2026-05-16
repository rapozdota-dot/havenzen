@echo off
setlocal
set APP_DIR=%~dp0
set PHP_EXE=C:\xampp\php\php.exe

if not exist "%PHP_EXE%" (
  echo PHP was not found at %PHP_EXE%
  echo Update PHP_EXE in this file if XAMPP is installed somewhere else.
  pause
  exit /b 1
)

cd /d "%APP_DIR%"
echo Starting Havenzen local thermal print bridge on http://127.0.0.1:8765
echo Keep this window open while printing from the deployed website.
"%PHP_EXE%" -S 127.0.0.1:8765 tools\local_print_bridge.php
endlocal
