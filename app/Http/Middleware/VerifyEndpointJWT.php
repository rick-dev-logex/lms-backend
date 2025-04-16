<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;
use Illuminate\Support\Facades\Log;

class VerifyEndpointJWT
{
    public function handle(Request $request, Closure $next)
    {
        // Log::debug('VerifyEndpointJWT: Middleware iniciado', [
        //     'url' => $request->url(),
        //     'method' => $request->method(),
        // ]);

        try {
            $authHeader = $request->header('Authorization');
            // Log::debug('VerifyEndpointJWT: Authorization Header', ['header' => $authHeader]);

            $token = $request->bearerToken();
            // Log::debug('VerifyEndpointJWT: Bearer Token', ['token' => $token]);

            if (!$token) {
                Log::warning('VerifyEndpointJWT: Token no proporcionado');
                return response()->json(['error' => 'Token no proporcionado'], 401);
            }

            $key = env('JWT_SECRET', 'your-secure-endpoint-key');
            $decoded = JWT::decode($token, new Key($key, 'HS256'));

            if ($decoded->sub !== 'internal-app' || $decoded->scope !== 'mobile-api') {
                Log::warning('VerifyEndpointJWT: Token no autorizado', ['sub' => $decoded->sub ?? null]);
                return response()->json(['error' => 'Token no autorizado'], 401);
            }

            $request->attributes->add(['endpoint_user' => (array) $decoded]);
            // Log::debug('VerifyEndpointJWT: Token válido', ['sub' => $decoded->sub]);

            return $next($request);
        } catch (Exception $e) {
            Log::error('VerifyEndpointJWT: Error', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Token inválido o expirado'], 401);
        }
    }
}
