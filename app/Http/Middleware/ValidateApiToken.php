<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class ValidateApiToken
{
    public function handle(Request $request, Closure $next)
    {
        // Permitir solicitudes OPTIONS sin validación
        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }

        // Permitir acceso a la ruta de login sin validación
        if ($request->is('api/login') || $request->is('login')) {
            return $next($request);
        }
        // Si la ruta es de descarga, omite la validación
        if ($request->is('download-discounts-template') || $request->is('download-expenses-template')) {
            return $next($request);
        }

        // Obtener el JWT desde la cookie
        $jwt = $request->cookie('jwt-token');

        if (!$jwt) {
            return response()->json(['message' => 'No token provided'], 401);
        }

        try {
            $decoded = JWT::decode($jwt, new Key(config('jwt.secret'), 'HS256'));
            $request->attributes->add(['user' => $decoded]); // Opcional: agregar datos del usuario al request
            return $next($request);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Invalid token'], 401);
        }
    }
}
