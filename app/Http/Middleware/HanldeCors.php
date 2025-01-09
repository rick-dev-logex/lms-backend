<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class HandleCors
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Configura los encabezados CORS en la respuesta
        $response->headers->set('Access-Control-Allow-Origin', '*');  // Puedes especificar un dominio específico si lo prefieres
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');

        // Si la solicitud es de tipo OPTIONS (preflight), solo se devuelven los encabezados sin procesar
        if ($request->getMethod() == "OPTIONS") {
            return response('', 200, $response->headers->all());
        }

        return $response;
    }
}
