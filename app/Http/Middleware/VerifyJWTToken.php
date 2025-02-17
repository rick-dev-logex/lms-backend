<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cookie;

class VerifyJWTToken
{
    const TOKEN_EXPIRATION = 60 * 60 * 10; // 10 horas en segundos

    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken() ?? $request->cookie('jwt-token');

        if (!$token) {
            return response()->json(['error' => 'JWT Token not found'], 401);
        }

        try {
            $secret = config('jwt.secret');
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));

            // Verificar si el token expirará pronto (en los próximos 5 minutos)
            if ($decoded->exp - time() < 300) {
                Log::info('Token próximo a expirar, renovando...', ['exp' => $decoded->exp]);

                // Crear nuevo payload
                $newPayload = [
                    'user_id'     => $decoded->user_id,
                    'email'       => $decoded->email,
                    'name'        => $decoded->name,
                    'role'        => $decoded->role,
                    'permissions' => $decoded->permissions,
                    'iat'         => time(),
                    'exp'         => time() + self::TOKEN_EXPIRATION
                ];

                // Generar nuevo token
                $newToken = JWT::encode($newPayload, $secret, 'HS256');

                // Crear nueva cookie
                $cookie = Cookie::make(
                    'jwt-token',
                    $newToken,
                    self::TOKEN_EXPIRATION / 60,
                    '/',
                    '.lms.logex.com.ec',
                    true,
                    false
                )->withSameSite('None');

                // Agregar el payload decodificado a la request
                $request->merge(['jwt_payload' => (array)$decoded]);

                // Continuar con la request pero adjuntar la nueva cookie
                $response = $next($request);
                return $response->withCookie($cookie);
            }

            // Si el token no está próximo a expirar, continuar normalmente
            $request->merge(['jwt_payload' => (array)$decoded]);
            return $next($request);
        } catch (\Firebase\JWT\ExpiredException $e) {
            Log::error('Token expirado', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Token expirado.',
                'code' => 'TOKEN_EXPIRED'
            ], 401);
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            Log::error('Firma del token inválida', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Firma del token inválida.',
                'code' => 'INVALID_SIGNATURE'
            ], 401);
        } catch (\Exception $e) {
            Log::error('Error al decodificar el token', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Token inválido.',
                'code' => 'INVALID_TOKEN'
            ], 401);
        }
    }
}
