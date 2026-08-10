@echo off
setlocal
REM ---------------------------------------------------------------------------
REM Store Shift — production build.
REM Produces:
REM   backend\resources\spa\index.html   (served by Laravel — NOT in public)
REM   public-assets.zip                  (unzip into public_html on the server)
REM ---------------------------------------------------------------------------

set "ROOT=%~dp0"

echo [1/4] Building frontend...
pushd "%ROOT%frontend"
call npm run build || (echo BUILD FAILED & popd & exit /b 1)
popd

echo [2/4] Installing SPA shell into backend\resources\spa ...
if not exist "%ROOT%backend\resources\spa" mkdir "%ROOT%backend\resources\spa"
copy /Y "%ROOT%frontend\dist\index.html" "%ROOT%backend\resources\spa\index.html" >nul

echo [3/4] Refreshing backend\public assets ...
if exist "%ROOT%backend\public\assets" rmdir /S /Q "%ROOT%backend\public\assets"
xcopy /E /I /Y "%ROOT%frontend\dist\assets" "%ROOT%backend\public\assets" >nul
copy /Y "%ROOT%frontend\dist\favicon.svg" "%ROOT%backend\public\favicon.svg" >nul
copy /Y "%ROOT%frontend\dist\icons.svg"   "%ROOT%backend\public\icons.svg"   >nul
REM The SPA shell must never sit in public (collides with host placeholders).
if exist "%ROOT%backend\public\index.html" del /Q "%ROOT%backend\public\index.html"

echo [4/4] Packaging public-assets.zip ...
if exist "%ROOT%.deploy" rmdir /S /Q "%ROOT%.deploy"
mkdir "%ROOT%.deploy"
xcopy /E /I /Y "%ROOT%frontend\dist\assets" "%ROOT%.deploy\assets" >nul
copy /Y "%ROOT%frontend\dist\favicon.svg" "%ROOT%.deploy\" >nul
copy /Y "%ROOT%frontend\dist\icons.svg"   "%ROOT%.deploy\" >nul
powershell -NoProfile -Command "Compress-Archive -Path '%ROOT%.deploy\*' -DestinationPath '%ROOT%public-assets.zip' -Force"
rmdir /S /Q "%ROOT%.deploy"

echo.
echo ============================================================
echo  Build complete.
echo.
echo  UPLOAD to the server:
echo    1) public-assets.zip  -^> unzip inside public_html
echo    2) backend\resources\spa\index.html -^> backend\resources\spa\
echo.
echo  Then on the server:  php artisan route:clear ^&^& php artisan config:cache
echo ============================================================
pause
