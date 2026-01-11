<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force HTTPS Middleware
 * 
 * SECURITY FIX: Bug #9 - Enforce HTTPS in production for all requests
 */
class ForceHttps
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only enforce HTTPS in production
        if (!app()->environment('production')) {
            return $next($request);
        }

        // Force all URLs to be generated with HTTPS
        URL::forceScheme('https');

        // Check if request is already secure
        if ($request->secure()) {
            return $next($request);
        }

        // Check if behind a proxy/load balancer that terminates SSL
        if ($this->isBehindTrustedProxy($request)) {
            return $next($request);
        }

        // Redirect HTTP to HTTPS
        return $this->redirectToHttps($request);
    }

    /**
     * Check if request is behind a trusted proxy (e.g., CloudFlare, AWS ELB)
     */
    private function isBehindTrustedProxy(Request $request): bool
    {
        // Check for common proxy headers indicating HTTPS
        $httpsHeaders = [
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_SSL' => 'on',
            'HTTP_CLOUDFLARE_VISITOR' => 'https',
        ];

        foreach ($httpsHeaders as $header => $value) {
            $headerValue = $request->server->get($header);
            
            if ($headerValue) {
                // For CloudFlare visitor header, parse JSON
                if ($header === 'HTTP_CLOUDFLARE_VISITOR') {
                    $visitor = json_decode($headerValue, true);
                    if (isset($visitor['scheme']) && $visitor['scheme'] === 'https') {
                        return true;
                    }
                } elseif (strcasecmp($headerValue, $value) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Redirect to HTTPS version of URL
     */
    private function redirectToHttps(Request $request): Response
    {
        $httpsUrl = 'https://' . $request->getHost() . $request->getRequestUri();

        return redirect($httpsUrl, 301); // Permanent redirect
    }
}
