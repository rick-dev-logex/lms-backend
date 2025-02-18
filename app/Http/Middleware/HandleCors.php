<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleCors
{
    public function handle(Request $request, Closure $next): Response
    {
        // Para peticiones OPTIONS, respondemos inmediatamente
        if ($request->isMethod('OPTIONS')) {
            $response = new \Illuminate\Http\Response('', 200);
        } else {
            $response = $next($request);
        }

        // Remover TODOS los headers CORS existentes
        foreach ($response->headers->all() as $key => $value) {
            if (stripos($key, 'access-control-') === 0) {
                $response->headers->remove($key);
            }
        }

        // Establecer el origen permitido
        $allowedOrigin = 'https://lms.logex.com.ec';

        // Establecer los headers CORS
        $response->header('Access-Control-Allow-Origin', $allowedOrigin);
        $response->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin');
        $response->header('Access-Control-Allow-Credentials', 'true');
        $response->header('Access-Control-Max-Age', '86400');

        // Agregar Vary header para cacheo correcto
        $response->header('Vary', 'Origin');

        return $response;
    }
}
