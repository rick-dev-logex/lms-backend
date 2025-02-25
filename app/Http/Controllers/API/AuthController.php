<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    const TOKEN_EXPIRATION = 60 * 60 * 10; // 10 horas en segundos
    /**
     * Registro de usuario
     */
    public function register(Request $request)
    {
        if ($request->isMethod('OPTIONS')) {
            return response()->json('OK', 200);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'role_id' => 'required|exists:roles,id'
        ]);

        try {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make("L0g3x2025*"),
                'role_id' => $request->role_id
            ]);

            // Si se proporcionan permisos específicos, los asignamos
            if ($request->has('permissions')) {
                $user->permissions()->attach($request->permissions);
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'Usuario creado exitosamente',
                'user' => $user->load(['role', 'permissions']),
                'access_token' => $token,
                'token_type' => 'Bearer'
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al iniciar sesión',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
            'remember' => 'boolean'
        ]);

        try {
            $user = User::where('email', $request->email)
                ->with(['role', 'permissions', 'assignedProjects'])
                ->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                throw ValidationException::withMessages([
                    'email' => ['Las credenciales proporcionadas son incorrectas.'],
                ]);
            }

            // Duración del token basada en "remember"
            $tokenDuration = $request->remember ? 60 * 60 * 10 : 60 * 30; // 10 horas o 30 minutos

            $payload = [
                'user_id'     => $user->id,
                'email'       => $user->email,
                'name'        => $user->name,
                'role'        => $user->role?->name,
                'permissions' => $user->permissions->pluck('name'),
                'iat'         => time(),
                'exp'         => time() + $tokenDuration
            ];

            $jwt = JWT::encode($payload, config('jwt.secret'), 'HS256');

            $isProduction = app()->environment('production');

            $cookie = Cookie::make(
                'jwt-token',
                $jwt,
                $tokenDuration / 60, // Convertir segundos a minutos
                '/',
                $isProduction ? '.lms.logex.com.ec' : null, // Dominio en producción, null en local
                $isProduction,  // Secure solo en producción (HTTPS)
                false           // HttpOnly (false para que JS lo lea)
            )->withSameSite($isProduction ? 'None' : 'Lax');

            // Inyectar assignedProjects dentro del objeto user
            $assignedProjects = $user->assignedProjects ? $user->assignedProjects->projects : [];
            $userData = $user->toArray();
            $userData['assignedProjects'] = $assignedProjects;

            return response()->json([
                'message' => 'Login successful',
                'user'    => $userData,
            ])->withCookie($cookie);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error en login', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Error al iniciar sesión',
                'error'   => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Cerrar sesión
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesión cerrada exitosamente']);
    }

    /**
     * Renovar token
     */
    public function refresh(Request $request)
    {
        try {
            $decoded = JWT::decode(
                $request->token,
                new Key(config('jwt.secret'), 'HS256') // Usa la misma clave que en login
            );

            $user = User::with(['role', 'permissions', 'assignedProjects'])->find($decoded->user_id);

            if (!$user) {
                return response()->json(['error' => 'Usuario no encontrado'], 404);
            }

            $newPayload = [
                'user_id'     => $user->id,
                'email'       => $user->email,
                'name'        => $user->name,
                'role'        => $user->role?->name,
                'permissions' => $user->permissions->pluck('name'),
                'iat'         => time(),
                'exp'         => time() + self::TOKEN_EXPIRATION
            ];

            $newToken = JWT::encode($newPayload, config('jwt.secret'), 'HS256');

            $cookie = Cookie::make(
                'jwt-token',
                $newToken,
                self::TOKEN_EXPIRATION / 60,
                '/',
                '.lms.logex.com.ec',
                true,
                false
            )->withSameSite('None');

            // Inyectar assignedProjects dentro del objeto user
            $assignedProjects = $user->assignedProjects ? $user->assignedProjects->projects : [];
            $userData = $user->toArray();
            $userData['assignedProjects'] = $assignedProjects;

            return response()->json([
                'message' => 'Token refreshed successfully',
                'user'    => $userData,
            ])->withCookie($cookie);
        } catch (\Exception $e) {
            Log::error('Error refreshing token: ' . $e->getMessage());
            return response()->json(['error' => 'Error al renovar el token'], 401);
        }
    }


    /**
     * Cambiar contraseña
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|min:8|different:current_password|confirmed'
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['error' => 'La contraseña actual es incorrecta'], 400);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json(['message' => 'Contraseña actualizada exitosamente']);
    }

    /**
     * Enviar enlace para resetear contraseña
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json(['message' => 'Se ha enviado el enlace de recuperación a su correo']);
        }

        throw ValidationException::withMessages([
            'email' => [trans($status)],
        ]);
    }

    /**
     * Resetear contraseña
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->password = Hash::make($password);
                $user->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Contraseña restablecida exitosamente']);
        }

        throw ValidationException::withMessages([
            'email' => [trans($status)],
        ]);
    }
}
