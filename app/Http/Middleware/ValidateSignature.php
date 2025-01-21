<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Symfony\Component\HttpFoundation\Response;

class ValidateSignature
{
    /**
     * Handle an incoming request.
     *
     * @throws \Illuminate\Routing\Exceptions\InvalidSignatureException
     */
    public function handle(Request $request, Closure $next, ?string $relative = null): Response
    {
        if ($request->hasValidSignature($relative !== 'relative')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Invalid signature.'
            ], 403);
        }

        throw new InvalidSignatureException;
    }
}
