<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Basic security headers
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        
        // HSTS for HTTPS connections
        if ($request->secure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        // Stricter CSP - removed unsafe-inline and unsafe-eval
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self'",  // Removed: 'unsafe-inline' 'unsafe-eval'
            "style-src 'self'",   // Removed: 'unsafe-inline'
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            "connect-src 'self' wss: https:",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",  // Added: prevent Flash/Java
            "upgrade-insecure-requests",  // Added: auto-upgrade HTTP to HTTPS
        ]);
        
        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}
