<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Symfony\Component\HttpFoundation\Response;

class VerifyJwtToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $jwt = $request->header('X-JWT-Token');

            if (!$jwt) {
                return response()->json(['error' => 'JWT Token not found'], 401);
            }

            $decoded = JWT::decode($jwt, new Key(config('app.key'), 'HS256'));

            // Verificar si el token está por expirar (menos de 2 minutos)
            if ($decoded->exp - time() < 120) {
                // Agregar header para indicar que el token está por expirar
                $response = $next($request);
                $response->headers->set('X-Token-Expire-Warning', 'true');
                return $response;
            }

            return $next($request);
        } catch (ExpiredException $e) {
            return response()->json(['error' => 'Token has expired'], 401);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid token'], 401);
        }
    }
}
