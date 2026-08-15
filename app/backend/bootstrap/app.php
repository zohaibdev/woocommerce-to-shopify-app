<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'shopify.session' => \App\Http\Middleware\AuthenticateSessionToken::class,
            'admin.auth' => \App\Http\Middleware\AuthenticateAdmin::class,
        ]);

        // Appended to the API group so ordinary polling traffic keeps the queue
        // moving on hosting with no persistent worker. It does its work in
        // terminate(), after the response — see KeepQueueAlive.
        $middleware->api(append: [
            \App\Http\Middleware\KeepQueueAlive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
