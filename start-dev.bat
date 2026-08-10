@echo off
setlocal
REM One-command launcher for the Store Shift dev stack.
REM Opens 4 windows: Laravel API, Queue Worker, Vite frontend, Cloudflare tunnel.

set "ROOT=%~dp0"
set "CF=C:\Program Files (x86)\cloudflared\cloudflared.exe"

echo ==============================================
echo   Starting Store Shift (WooCommerce -^> Shopify) stack
echo ==============================================
echo.

start "Store Shift: Laravel API"   cmd /k "cd /d "%ROOT%backend" && php artisan serve --host=127.0.0.1 --port=8000"
start "Store Shift: Queue Worker"  cmd /k "cd /d "%ROOT%backend" && php artisan queue:work --tries=5 --timeout=120"
start "Store Shift: Vite Frontend" cmd /k "cd /d "%ROOT%frontend" && npm run dev"
start "Store Shift: Tunnel"        cmd /k ""%CF%" tunnel --url http://localhost:5173 --no-autoupdate"

echo Four windows are starting:
echo   - Laravel API      http://127.0.0.1:8000
echo   - Queue Worker     (processes migrations)
echo   - Vite Frontend    http://localhost:5173
echo   - Cloudflare Tunnel (public URL prints in its window)
echo.
echo IMPORTANT: the tunnel prints a NEW https://xxxxx.trycloudflare.com URL each run.
echo If using Shopify, update SHOPIFY_APP_URL in backend\.env and your Partner
echo dashboard App URL + redirect URL to match, then run:  php artisan config:clear
echo.
pause
