<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class HandleCors
{
    public function handle(Request $request, Closure $next): Response
    {
        Log::info('HandleCors ejecutado', [
            'method' => $request->method(),
            'path' => $request->path(),
            'origin' => $request->headers->get('Origin'),
        ]);
        // Si es una petición OPTIONS, respondemos inmediatamente
        $response = $request->isMethod('OPTIONS') ? response('', 200) : $next($request);

        // Obtener el origen de la solicitud
        $origin = $request->headers->get('Origin');

        // Lista de orígenes permitidos (definida en config/cors.php)
        $allowedOrigins = array_map('trim', config('cors.allowed_origins'));
        Log::info('Origen solicitado: ' . $origin);
        Log::info('Orígenes permitidos: ' . implode(', ', $allowedOrigins));
        // Verificar si el origen está permitido
        if ($origin && in_array($origin, $allowedOrigins)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        } else {
            return response('Origin not allowed', 403);
        }

        // Establecer los demás encabezados CORS
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-Auth-Token, Origin, Accept');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Max-Age', '86400');
        $response->headers->set('Vary', 'Origin');

        return $response;
    }
}
