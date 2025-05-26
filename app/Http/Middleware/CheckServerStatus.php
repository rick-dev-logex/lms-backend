<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckServerStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isDownForMaintenance()) {
            return response()->json(["under_maintenance" => "true", "responseText" => "¡Estamos en mantenimiento!"], 503);
        }

        return $next($request);
    }
}