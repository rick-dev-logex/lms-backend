<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CheckRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $jwtPayload = $request->get('jwt_payload', []);
        $userRole = $jwtPayload['role'] ?? null;

        if (!$userRole || !in_array($userRole, $roles)) {
            Log::warning('Acceso denegado por rol insuficiente', [
                'user_role'      => $userRole,
                'required_roles' => $roles,
            ]);
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
