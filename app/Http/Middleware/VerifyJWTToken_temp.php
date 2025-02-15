<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Log;

class VerifyJWTToken
{
    public function handle(Request $request, Closure $next)
    {
        // Buscar token en header o en cookie
        $token = $request->bearerToken() ?? $request->cookie('jwt-token');

        if (!$token) {
            return response()->json(['error' => 'JWT Token not found'], 401);
        }

        try {
            $secret = config('jwt.secret');

            // Decodificar el token
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));

            Log::info('JWT Payload in request', ['payload' => (array)$decoded]);
            // Agregar el payload decodificado a la request (si es necesario)
            $request->merge(['jwt_payload' => (array)$decoded]);

            return $next($request);
        } catch (\Firebase\JWT\ExpiredException $e) {
            Log::error('Token expirado', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Token expirado.'], 401);
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            Log::error('Firma del token inválida', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Firma del token inválida.'], 401);
        } catch (\Exception $e) {
            Log::error('Error al decodificar el token', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Token inválido.'], 401);
        }
    }
}
