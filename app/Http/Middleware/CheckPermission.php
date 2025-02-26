<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CheckPermission
{
    /**
     * Los permisos requeridos se pueden pasar como argumentos.
     */
    public function handle(Request $request, Closure $next, ...$requiredPermissions)
    {
        // Suponiendo que el payload del JWT fue inyectado en la request
        $jwtPayload = $request->get('jwt_payload', []);
        $userPermissions = $jwtPayload['permissions'] ?? [];

        // Agregar logs para depuración
        // Log::info('CheckPermission middleware', [
        //     'required_permissions' => $requiredPermissions,
        //     'user_permissions'     => $userPermissions,
        // ]);

        // Si se requiere al menos uno de los permisos, por ejemplo:
        $hasPermission = false;
        foreach ($requiredPermissions as $permission) {
            if (in_array($permission, $userPermissions)) {
                $hasPermission = true;
                break;
            }
        }

        if (!$hasPermission) {
            Log::warning('Acceso denegado por permisos insuficientes', [
                'user_permissions'     => $userPermissions,
                'required_permissions' => $requiredPermissions,
            ]);
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
