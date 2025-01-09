<?php

namespace App\Http;

use App\Http\Middleware\HandleCors;

class Middleware
{
    /**
     * The application's global HTTP middleware stack.
     *
     * This middleware will be run during every request to your application.
     *
     * @var array
     */
    protected $middleware = [
        // Otros middlewares globales
        HandleCors::class,
    ];
}
