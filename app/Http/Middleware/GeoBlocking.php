<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class GeoBlocking
{
    /**
     * Allowed countries (ISO 3166-1 alpha-2 codes)
     */
    private const ALLOWED_COUNTRIES = ['ID']; // Indonesia only

    /**
     * Whitelisted IPs (always allow)
     */
    private const IP_WHITELIST = [
        '127.0.0.1',
        '::1',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip in local development
        if (app()->environment('local')) {
            return $next($request);
        }

        $clientIp = $this->getClientIp($request);

        // Allow whitelisted IPs
        if (in_array($clientIp, self::IP_WHITELIST)) {
            return $next($request);
        }

        // Get country from IP
        $country = $this->getCountryFromIp($clientIp);

        // Block if not from allowed country
        if (!in_array($country, self::ALLOWED_COUNTRIES)) {
            Log::warning('Blocked request from non-Indonesian IP', [
                'ip' => $clientIp,
                'country' => $country,
                'path' => $request->path(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'error' => 'Access Denied',
                'message' => 'Service is only available in Indonesia',
            ], 403);
        }

        return $next($request);
    }

    /**
     * Get client IP address
     */
    private function getClientIp(Request $request): string
    {
        if ($request->header('CF-Connecting-IP')) {
            return $request->header('CF-Connecting-IP'); // Cloudflare
        }

        if ($request->header('X-Forwarded-For')) {
            $ips = explode(',', $request->header('X-Forwarded-For'));
            return trim($ips[0]);
        }

        if ($request->header('X-Real-IP')) {
            return $request->header('X-Real-IP');
        }

        return $request->ip();
    }

    /**
     * Get country code from IP address (with caching)
     */
    private function getCountryFromIp(string $ip): string
    {
        // Check cache first (cache for 24 hours)
        $cacheKey = "geo:country:{$ip}";
        
        return Cache::remember($cacheKey, now()->addDay(), function () use ($ip) {
            try {
                // Try Cloudflare geolocation first (if behind Cloudflare)
                if (request()->header('CF-IPCountry')) {
                    return request()->header('CF-IPCountry');
                }

                // Use ip-api.com (free, 45 req/min limit)
                $response = Http::timeout(3)->get("http://ip-api.com/json/{$ip}", [
                    'fields' => 'status,country,countryCode,query',
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    if ($data['status'] === 'success') {
                        return $data['countryCode'];
                    }
                }

                // Fallback: check if IP is Indonesian (common ranges)
                if ($this->isIndonesianIp($ip)) {
                    return 'ID';
                }

                return 'UNKNOWN';

            } catch (\Exception $e) {
                Log::error('Geo-location lookup failed', [
                    'ip' => $ip,
                    'error' => $e->getMessage(),
                ]);
                
                // Fail open: allow on error
                return 'ID';
            }
        });
    }

    /**
     * Check if IP is from common Indonesian ranges
     */
    private function isIndonesianIp(string $ip): bool
    {
        // Common Indonesian ISP ranges
        $indonesianRanges = [
            '103.0.0.0/8',    // Various Indonesian ISPs
            '110.0.0.0/7',    // Telkom, Indosat
            '114.0.0.0/8',    // Various ISPs
            '118.0.0.0/8',    // Biznet, CBN
            '180.0.0.0/8',    // Various ISPs
            '182.0.0.0/8',    // XL Axiata
            '202.0.0.0/8',    // Multiple Indonesian ISPs
        ];

        foreach ($indonesianRanges as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
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
