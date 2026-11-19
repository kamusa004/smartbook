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
REM Bind to 0.0.0.0 (not just 127.0.0.1) so laptops/phones on the same Wi-Fi
REM can open http://<this-computer-IP>:8080 as well as the Cloudflare link.
netstat -ano | findstr ":8080" | findstr "LISTENING" >nul 2>&1
if errorlevel 1 (
  start "SmartBook Server" /min /D "%APP%" "%RT%\php\php.exe" -S 0.0.0.0:8080 router.php
)

REM --- Secure tunnel: lets people log in from anywhere online. ---
REM Only start one tunnel. A second one would create a different web address.
tasklist /FI "IMAGENAME eq cloudflared.exe" 2>nul | findstr /I "cloudflared.exe" >nul 2>&1
if errorlevel 1 (
  start "SmartBook Tunnel" /min "%RT%\cloudflared.exe" tunnel --url http://127.0.0.1:8080 --logfile "%RT%\tunnel.log"
)

REM --- Refresh the shareable links and the hosted page with the new address. ---
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%APP%\update-smartbook-link.ps1" >> "%RT%\smartbook-link-update.log" 2>&1
endlocal
exit /b 0
