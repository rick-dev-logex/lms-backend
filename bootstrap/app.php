<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        api: __DIR__ . '/../routes/api.php'
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Global middleware (solo para rutas web)
        $middleware->use([
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
        ]);

        // API-specific middleware
        $middleware->api([
            'throttle:60,1',
            \App\Http\Middleware\HandleCors::class,
            \App\Http\Middleware\ValidateApiToken::class,
        ]);

        // Aliases
        $middleware->alias([
            'auth' => \App\Http\Middleware\Authenticate::class,
            'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
            'verify.jwt' => \App\Http\Middleware\VerifyJWTToken::class,
            'verify.endpoint.jwt' => \App\Http\Middleware\VerifyEndpointJWT::class, // Para API consumible fuera de la app LMS
            'role' => \App\Http\Middleware\CheckRole::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\Exception $e, $request) {
            if ($request->is('api/*')) {
                if ($e instanceof MethodNotAllowedHttpException) {
                    return response()->json([
                        'message' => 'Método no permitido para esta ruta.',
                        'exception' => get_class($e),
                    ], 405);
                }
                return response()->json([
                    'message' => $e->getMessage(),
                    'exception' => get_class($e),
                ], 500);
            }
        });
    })->create();
