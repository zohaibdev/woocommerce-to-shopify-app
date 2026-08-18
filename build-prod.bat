@echo off
setlocal enabledelayedexpansion

REM ===========================================================================
REM  Shift Shop - production build
REM
REM  Produces the two things a deploy needs and packs them into
REM  store-shift-deploy.zip:
REM
REM    app\assets\                        built JS/CSS, served at /app/assets/
REM    app\backend\resources\spa\index.html   SPA shell, served BY Laravel
REM
REM  The SPA shell deliberately does NOT live in backend\public\ - Laravel
REM  serves it from resources\ for any non-/api path (see routes\web.php).
REM
REM  The zip is written with PYTHON, not PowerShell's Compress-Archive: that
REM  cmdlet writes BACKSLASHES into zip entries, so Linux extracts files
REM  literally named "assets\index-x.js" and every /assets/* request 404s.
REM ===========================================================================

set ROOT=%~dp0
set FRONTEND=%ROOT%app\frontend
set DIST=%FRONTEND%\dist
set APPDIR=%ROOT%app

echo.
echo === 1/5  Type-checking frontend =========================================
pushd "%FRONTEND%"
call npx tsc -b
if errorlevel 1 (
    echo.
    echo BUILD FAILED: TypeScript errors above. Nothing was copied.
    popd & exit /b 1
)

echo.
echo === 2/5  Building frontend ==============================================
call npm run build
if errorlevel 1 (
    echo.
    echo BUILD FAILED: vite build errored. Nothing was copied.
    popd & exit /b 1
)
popd

if not exist "%DIST%\index.html" (
    echo BUILD FAILED: %DIST%\index.html missing.
    exit /b 1
)

echo.
echo === 3/5  Copying built assets ===========================================
REM Wipe first: filenames are content-hashed, so stale bundles would otherwise
REM pile up in the deploy folder forever and get shipped on every release.
if exist "%APPDIR%\assets" rmdir /s /q "%APPDIR%\assets"
mkdir "%APPDIR%\assets"
xcopy /e /i /y /q "%DIST%\assets\*" "%APPDIR%\assets\" >nul
if errorlevel 1 exit /b 1

if not exist "%APPDIR%\backend\resources\spa" mkdir "%APPDIR%\backend\resources\spa"
copy /y "%DIST%\index.html" "%APPDIR%\backend\resources\spa\index.html" >nul
if errorlevel 1 exit /b 1

for %%f in ("%DIST%\favicon.svg" "%DIST%\icons.svg") do (
    if exist %%f copy /y %%f "%APPDIR%\" >nul
)

echo Copied assets and SPA shell.

echo.
echo === 4/5  Running backend tests ==========================================
pushd "%ROOT%app\backend"
call php artisan test
if errorlevel 1 (
    echo.
    echo BUILD FAILED: backend tests are red. Fix them before deploying.
    popd & exit /b 1
)
popd

echo.
echo === 5/5  Packing store-shift-deploy.zip =================================
python "%ROOT%deploy\make-zip.py"
if errorlevel 1 (
    echo.
    echo ZIP FAILED - is python on PATH?
    exit /b 1
)

echo.
echo Done. Upload store-shift-deploy.zip, then follow DEPLOYMENT.md section 9.
endlocal
