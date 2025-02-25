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
        if ($request->is('api/login')) {
            return $next($request);
        }

        if (Auth::check()) {
            return $next($request);
        }
        // Verificar si el encabezado Authorization tiene el formato Bearer <token>
        $token = $request->bearerToken();

        // Token que "quemaste" o guardaste en Secrets Manager
        $validToken = env('API_ACCESS_TOKEN');

        // Validación
        if ($token && $token === $validToken) {
            return $next($request);
        }

        // Si el token es inválido
        return response()->json(['message' => 'Forbidden'], 403);
    }
}
