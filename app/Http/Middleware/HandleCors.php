<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class HandleCors
{
    public function handle(Request $request, Closure $next)
    {
        $request->isMethod('OPTIONS') ? $response = response()->json([], 200) : $response = $next($request);

        $allowedOrigins = ['https://lms.logex.com.ec', 'http://localhost:3000', 'http://localhost:3001'];
        $origin = $request->headers->get('Origin');
        $response->headers->set('Access-Control-Allow-Origin', in_array($origin, $allowedOrigins) ? $origin : 'https://lms.logex.com.ec');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PATCH, PUT, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');

        return $response;
    }
}
