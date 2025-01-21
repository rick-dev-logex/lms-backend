<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class HandleCors
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (!$response instanceof \Illuminate\Http\Response && !$response instanceof \Illuminate\Http\JsonResponse) {
            return $response;
        }

        // Obtén los origins permitidos desde la configuración
        $allowedOrigins = explode(',', config('app.allowed_origins'));
        $origin = $request->header('Origin');

        if ($origin && (in_array('*', $allowedOrigins) || in_array($origin, $allowedOrigins))) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }

        $response->headers->set('Access-Control-Allow-Methods', implode(',', config('app.allowed_methods', [])));
        $response->headers->set('Access-Control-Allow-Headers', implode(',', config('app.allowed_headers', [])));
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Max-Age', config('app.max_age', 86400));

        if ($request->getMethod() === 'OPTIONS') {
            $response->setStatusCode(200);
        }

        return $response;
    }
}
