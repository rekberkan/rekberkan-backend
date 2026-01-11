<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AdminIpWhitelist
{
    /**
     * Handle an incoming request.
     * Restrict admin panel access to whitelisted IPs only
     */
    public function handle(Request $request, Closure $next): Response
    {
        $clientIp = $this->getClientIp($request);
        $whitelist = $this->getWhitelistedIps();

        // Allow all IPs if whitelist is empty (development mode)
        if (empty($whitelist)) {
            Log::warning('Admin IP whitelist is empty - allowing all IPs');
            return $next($request);
        }

        // Check if IP is whitelisted
        if (!$this->isIpWhitelisted($clientIp, $whitelist)) {
            Log::warning('Blocked admin access from non-whitelisted IP', [
                'ip' => $clientIp,
                'user' => $request->user()?->email,
                'path' => $request->path(),
            ]);

            return response()->json([
                'error' => 'Access Denied',
                'message' => 'Admin panel access is restricted to whitelisted IP addresses only.',
            ], 403);
        }

        return $next($request);
    }

    /**
     * Get client IP address (considering proxies)
     */
    private function getClientIp(Request $request): string
    {
        // Check for X-Forwarded-For header (behind proxy/load balancer)
        if ($request->header('X-Forwarded-For')) {
            $ips = explode(',', $request->header('X-Forwarded-For'));
            return trim($ips[0]);
        }

        // Check for X-Real-IP header
        if ($request->header('X-Real-IP')) {
            return $request->header('X-Real-IP');
        }

        return $request->ip();
    }

    /**
     * Get whitelisted IPs from config
     */
    private function getWhitelistedIps(): array
    {
        return config('security.admin_ip_whitelist', []);
    }

    /**
     * Check if IP is in whitelist (supports CIDR notation)
     */
    private function isIpWhitelisted(string $ip, array $whitelist): bool
    {
        foreach ($whitelist as $range) {
            // Exact match
            if ($ip === $range) {
                return true;
            }

            // CIDR notation match
            if (str_contains($range, '/')) {
                if ($this->ipInRange($ip, $range)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if IP is in CIDR range
     */
    private function ipInRange(string $ip, string $range): bool
    {
        [$subnet, $mask] = explode('/', $range);
        
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskLong = -1 << (32 - (int) $mask);
        
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}
