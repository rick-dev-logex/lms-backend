<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedOrigins = [
            'https://lms.logex.com.ec',
            'https://api.lms.logex.com.ec',
            'https://lms-backend-898493889976.us-east1.run.app',
            'http://localhost:3000',
        ];

        $origin = $request->header('Origin');

        $headers = [
            'Access-Control-Allow-Origin' => in_array($origin, $allowedOrigins) ? $origin : '',
            'Access-Control-Allow-Methods' => 'POST, GET, OPTIONS, PUT, PATCH, DELETE',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, X-Auth-Token, Origin, Accept',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Expose-Headers' => 'Location',
            'Access-Control-Max-Age' => '86400',
            'Vary' => 'Origin',
        ];

        if ($request->isMethod('OPTIONS')) {
            return response()->json('OK', 200, $headers);
        }

        $response = $next($request);

        foreach ($headers as $key => $value) {
            $response->headers->set($key, $value);
        }

        if ($response->headers->has('Set-Cookie')) {
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
