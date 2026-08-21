<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| Shop Shift — PRODUCTION front controller  (public_html/app/index.php)
|--------------------------------------------------------------------------
| The Laravel app lives in a `backend` folder NEXT TO this file, so every path
| points into ./backend/ — NOT ../ like a stock Laravel install.
|
|   public_html/
|   ├── index.html          <- landing page
|   └── app/
|       ├── index.php       <- this file
|       ├── .htaccess
|       ├── assets/
|       └── backend/        <- Laravel (app, vendor, .env, storage, resources)
|
| Do NOT overwrite this with backend/public/index.php — that one uses ../ and
| will fatal here with "Failed to open stream: .../vendor/autoload.php".
*/

// Maintenance mode...
if (file_exists($maintenance = __DIR__.'/backend/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Composer autoloader...
require __DIR__.'/backend/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/backend/bootstrap/app.php';

$app->handleRequest(Request::capture());
