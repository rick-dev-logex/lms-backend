<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiToken
{
    public function handle(Request $request, Closure $next)
    {
        // Permitir solicitudes OPTIONS sin validación
        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }

        // Obtener el header "Origin"
        $origin = $request->headers->get('Origin');

        // Si el origen es localhost o el dominio de LMS, se omite la validación del token
        if ($origin && (strpos($origin, 'localhost') !== false || strpos($origin, 'lms.logex.com.ec') !== false)) {
            return $next($request);
        }

        // Permitir acceso a la ruta de login sin validación
        if ($request->is('api/login') || $request->is('login')) {
            return $next($request);
        }

        // Si ya está autenticado, continuar
        if (Auth::check()) {
            return $next($request);
        }

        // Validar token en el encabezado Authorization (formato Bearer <token>)
        $token = $request->bearerToken();
        $validToken = env('API_ACCESS_TOKEN');

        if ($token && $token === $validToken) {
            return $next($request);
        }

        return response()->json(['message' => 'Forbidden'], 403);
    }
}
