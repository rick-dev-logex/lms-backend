<?php

namespace App\Services;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;

class AuthService
{
    public function getUser(Request $request)
    {
        $jwtToken = $request->cookie('jwt-token');
        if (!$jwtToken) {
            throw new \Exception("No se encontró el token de autenticación en la cookie.");
        }

        $decoded = JWT::decode($jwtToken, new Key(env('JWT_SECRET'), 'HS256'));
        $userId = $decoded->user_id ?? null;
        if (!$userId) {
            throw new \Exception("No se encontró el ID de usuario en el token JWT.");
        }

        $user = User::find($userId);
        if (!$user) {
            throw new \Exception("Usuario no encontrado.");
        }

        return $user;
    }
}
