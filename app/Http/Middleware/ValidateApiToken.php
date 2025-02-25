<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        // Obtiene el token de la URL (por ejemplo: ?authtkn=...)
        $token = $request->query('authtkn');

        // Compara con el token definido en .env
        if (!$token || $token !== env('API_ACCESS_TOKEN')) {
            return response()->json(['message' => 'Acceso no autorizado'], 403);
        }

        return $next($request);
    }
}
