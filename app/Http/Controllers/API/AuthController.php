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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;

class AuthController extends Controller
{
    /**
     * Registro de usuario
     */
    public function register(Request $request)
    {
        // \Log::info('Datos recibidos en register:', $request->all());

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
            'email' => 'required|email',
            'password' => 'required',
        ]);

        try {
            $user = User::where('email', $request->email)
                ->with(['role', 'permissions'])
                ->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                throw ValidationException::withMessages([
                    'email' => ['Las credenciales proporcionadas son incorrectas.'],
                ]);
            }

            // Crear el payload
            $payload = [
                'user_id'     => $user->id,
                'email'       => $user->email,
                'name'        => $user->name,
                'role'        => $user->role?->name,
                'permissions' => $user->permissions->pluck('name'),
                'iat'         => time(),
                'exp'         => time() + ($request->remember ? 5 * 24 * 60 * 60 : 60 * 60)
            ];

            // Generar el token usando la clave de JWT (definida en config/jwt.php)
            $jwt = JWT::encode($payload, config('jwt.secret'), 'HS256');

            // Establecer el token en una cookie
            $cookie = Cookie::make('jwt-token', $jwt, 60 * 24, null, null, true, false);

            return response()->json([
                'message' => 'Login successful',
                'user'    => $user,
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
                new Key(config('app.key'), 'HS256')
            );

            if ($decoded->exp < time()) {
                return response()->json(['error' => 'Token expirado'], 401);
            }

            $user = User::find($decoded->user_id);

            $newPayload = [
                'user_id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'iat' => time(),
                'exp' => time() + (60 * 60) // 1 hora
            ];

            $newToken = JWT::encode($newPayload, config('app.key'), 'HS256');

            return response()->json([
                'access_token' => $user->createToken('auth_token')->plainTextToken,
                'jwt_token' => $newToken,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Token inválido'], 401);
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
