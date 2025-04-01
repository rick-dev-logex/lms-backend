<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');
        if (!Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }
        $user = Auth::guard('web')->user()->load(['role', 'permissions', 'assignedProjects']);
        $request->session()->regenerate();
        $userData = $user->toArray();
        $userData['assignedProjects'] = $user->assignedProjects ? $user->assignedProjects->projects : [];
        return response()->json(['data' => $userData, 'message' => 'Login successful'])->withCookie(cookie()->forget('jwt-token'));
    }

    public function logout(): JsonResponse
    {
        Auth::guard('web')->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();
        return response()->json(['message' => 'Sesión cerrada exitosamente']);
    }

    public function me(): JsonResponse
    {
        $user = Auth::guard('web')->user();
        if (!$user) return response()->json(['message' => 'No autenticado'], 401);
        $user = $user->load(['role', 'permissions', 'assignedProjects']);
        Log::debug("User:" . $user);
        $userData = $user->toArray();
        $userData['assignedProjects'] = $user->assignedProjects ? $user->assignedProjects->projects : [];
        return response()->json(['data' => $userData]);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink(
            $request->only('email')
        );

        return $status === Password::RESET_LINK_SENT
            ? response()->json(['message' => 'Enlace de restablecimiento enviado al correo'], 200)
            : response()->json(['message' => 'No se pudo enviar el enlace'], 422);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => 'Contraseña restablecida exitosamente'], 200)
            : response()->json(['message' => 'Error al restablecer la contraseña'], 422);
    }
}
