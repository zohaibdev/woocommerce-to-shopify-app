<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| Standard Laravel front controller
|--------------------------------------------------------------------------
| This is the ORIGINAL public/index.php and must keep the ../ paths — it is
| used by `php artisan serve` locally.
|
| The production front controller (which lives one level up at
| public_html/app/index.php and uses ./backend/ paths) is a SEPARATE file,
| kept in deploy-public_html/ — do not copy it over this one.
*/

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
