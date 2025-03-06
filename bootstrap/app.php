<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        api: __DIR__ . '/../routes/api.php'
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Global middleware - se ejecuta primero 
        $middleware->use([
            \App\Http\Middleware\TrustProxies::class,
            \App\Http\Middleware\HandleCors::class,
            \App\Http\Middleware\ValidateApiToken::class,
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
        ]);

        // API middleware group
        $middleware->api([
            'throttle:60,1'
        ]);

        // Aliases
        $middleware->alias([
            'auth' => \App\Http\Middleware\Authenticate::class,
            'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
            'verify.jwt' => \App\Http\Middleware\VerifyJWTToken::class,
            'role' => \App\Http\Middleware\CheckRole::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
