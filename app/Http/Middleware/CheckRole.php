<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        if (!$request->user() || !$request->user()->role) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (in_array($request->user()->role->name, $roles)) {
            return $next($request);
        }

        return response()->json(['message' => 'Unauthorized access'], 403);
    }
}
