<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser safety headers on every page: nothing is loaded from another site (the system works without internet), pages cannot be put in
 * someone else's frame, and a browser may not guess file types. Scripts keep 'unsafe-inline' and 'unsafe-eval' because the screens use Alpine.js.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'", false);
        $response->headers->set('X-Content-Type-Options', 'nosniff', false);
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $response->headers->set('Referrer-Policy', 'same-origin', false);
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()', false);

        return $response;
    }
}
