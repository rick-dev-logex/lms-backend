<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Illuminate\Support\Facades\Auth;

class VerifyJWT
{
    public function handle(Request $request, Closure $next)
    {
        try {
            if (!Auth::check()) {
                return response()->json(['message' => 'No te encuentras  autenticado'], 401);
            }

            $token = $request->bearerToken();

            if (!$token) {
                return response()->json(['message' => 'No se pudo encontrar el JWT token.'], 401);
            }

            JWT::decode($token, new Key(config('app.key'), 'HS256'));

            return $next($request);
        } catch (ExpiredException $e) {
            return response()->json(['message' => 'El token ha expirado'], 401);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Token inválido.'], 401);
        }
    }
}
