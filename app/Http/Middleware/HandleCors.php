<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedOrigins = [
            'https://lms.logex.com.ec',
            'https://api.lms.logex.com.ec',
            'https://lms-backend-898493889976.us-east1.run.app',
            'http://localhost:3000',
        ];
        // Si es una petición OPTIONS, respondemos inmediatamente
        $request->isMethod('OPTIONS') ? $response = response('', 200) : $response = $next($request);

        // Asegurar que estamos trabajando con un objeto Response
        if (!$response) {
            $response = response('', 204);
        }

        // Limpiar cualquier header CORS existente para evitar duplicados
        $response->headers->remove('Access-Control-Allow-Origin');
        $response->headers->remove('Access-Control-Allow-Methods');
        $response->headers->remove('Access-Control-Allow-Headers');
        $response->headers->remove('Access-Control-Allow-Credentials');
        $response->headers->remove('Access-Control-Expose-Headers');

        // Establecer los headers CORS
        $response->headers->set('Access-Control-Allow-Origin', 'https://lms.logex.com.ec');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-Auth-Token, Origin, Accept');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Max-Age', '86400');
        $response->headers->set('Vary', 'Origin');

        return $response;
    }
}
