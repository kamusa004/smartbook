@echo off
REM Starts the SmartBook Solutions local server (database + API).
REM Installed in the Startup folder: runs automatically when Windows logs in.
setlocal
set "RT=C:\Users\iTEK\Documents\Default Project\.runtime"
set "APP=C:\Users\iTEK\Desktop\smartbook"

REM --- Database (MariaDB, port 3307) ---
netstat -ano | findstr ":3307" | findstr "LISTENING" >nul 2>&1
if errorlevel 1 (
  start "SmartBook Database" /min /D "%RT%\mariadb\mariadb-11.4.4-winx64\bin" "%RT%\mariadb\mariadb-11.4.4-winx64\bin\mariadbd.exe" --defaults-file="%RT%\mariadb-data\my.ini" --console
)

REM --- API server (PHP on port 8080) ---
netstat -ano | findstr ":8080" | findstr "LISTENING" >nul 2>&1
if errorlevel 1 (
  start "SmartBook Server" /min /D "%APP%" "%RT%\php\php.exe" -S 127.0.0.1:8080 router.php
)
endlocal
exit /b 0
