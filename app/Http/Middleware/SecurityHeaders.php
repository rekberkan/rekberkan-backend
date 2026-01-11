<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security Headers Middleware
 * 
 * SECURITY FIX: Bug #3 - Enhanced CSP and security headers for API
 */
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
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), interest-cohort=()');
        
        // HSTS for HTTPS connections
        if ($request->secure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        // SECURITY FIX: Bug #3 - Comprehensive CSP for API
        // Stricter CSP - no unsafe-inline or unsafe-eval for production API
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'none'",  // API doesn't serve scripts
            "style-src 'none'",   // API doesn't serve styles
            "img-src 'self' data: https:",
            "font-src 'none'",    // API doesn't serve fonts
            "connect-src 'self'", // Only allow API calls to self
            "frame-ancestors 'none'",  // Prevent framing
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
            "media-src 'none'",   // No media files
            "worker-src 'none'",  // No web workers
            "manifest-src 'none'", // No manifest
            "upgrade-insecure-requests",
        ]);
        
        $response->headers->set('Content-Security-Policy', $csp);

        // Additional API security headers
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set('Cross-Origin-Embedder-Policy', 'require-corp');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        return $response;
    }
}
