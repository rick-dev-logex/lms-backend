<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckPermission
{
    public function handle(Request $request, Closure $next, ...$permissions)
    {
        if (!$request->user()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $userPermissions = $request->user()->permissions->pluck('name')->toArray();

        foreach ($permissions as $permission) {
            if (in_array($permission, $userPermissions)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Insufficient permissions'], 403);
    }
}
