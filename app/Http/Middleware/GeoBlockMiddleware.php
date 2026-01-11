<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Geo-blocking Middleware
 * 
 * SECURITY FIX: Bug #8 - Block high-risk countries for registration/sensitive operations
 */
class GeoBlockMiddleware
{
    /**
     * High-risk countries blocked for registration (ISO 3166-1 alpha-2)
     * Based on fraud statistics and regulatory concerns
     */
    private const BLOCKED_COUNTRIES = [
        'AF', // Afghanistan
        'BY', // Belarus
        'CU', // Cuba
        'IR', // Iran
        'IQ', // Iraq
        'KP', // North Korea
        'LY', // Libya
        'MM', // Myanmar
        'RU', // Russia (high fraud rate)
        'SD', // Sudan
        'SO', // Somalia
        'SS', // South Sudan
        'SY', // Syria
        'VE', // Venezuela
        'YE', // Yemen
        'ZW', // Zimbabwe
    ];

    /**
     * Whitelisted countries (prioritize Indonesia and neighbors)
     */
    private const WHITELISTED_COUNTRIES = [
        'ID', // Indonesia (primary market)
        'MY', // Malaysia
        'SG', // Singapore
        'TH', // Thailand
        'PH', // Philippines
        'VN', // Vietnam
        'BN', // Brunei
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Skip in development/testing environments
        if (!app()->environment('production')) {
            return $next($request);
        }

        // Skip for whitelisted IPs (admin access, etc.)
        if ($this->isWhitelistedIp($request->ip())) {
            return $next($request);
        }

        $ip = $request->ip();
        $countryCode = $this->getCountryCode($ip);

        // If country detection fails, log and allow (fail-open for UX)
        if (!$countryCode) {
            Log::warning('Geo-blocking: Failed to detect country', [
                'ip' => $ip,
                'user_agent' => $request->userAgent(),
            ]);
            return $next($request);
        }

        // Check if country is blocked
        if (in_array($countryCode, self::BLOCKED_COUNTRIES)) {
            Log::warning('Geo-blocking: Blocked country access attempt', [
                'ip' => $ip,
                'country' => $countryCode,
                'endpoint' => $request->path(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => false,
                'type' => 'https://rekberkan.com/errors/geo-blocked',
                'title' => 'Service Unavailable in Your Region',
                'status' => 403,
                'detail' => 'We are unable to provide services in your region due to regulatory restrictions.',
            ], 403);
        }

        // Add country code to request for analytics
        $request->attributes->set('country_code', $countryCode);

        return $next($request);
    }

    /**
     * Get country code from IP address using ip-api.com (free tier)
     * Cache results to minimize API calls
     */
    private function getCountryCode(string $ip): ?string
    {
        // Check cache first (24 hour TTL)
        $cacheKey = "geo_ip:{$ip}";
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        try {
            // Use ip-api.com free tier (45 requests/minute)
            $response = Http::timeout(3)
                ->get("http://ip-api.com/json/{$ip}", [
                    'fields' => 'status,countryCode',
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                if ($data['status'] === 'success') {
                    $countryCode = $data['countryCode'] ?? null;
                    
                    // Cache for 24 hours
                    Cache::put($cacheKey, $countryCode, now()->addDay());
                    
                    return $countryCode;
                }
            }

            Log::warning('Geo IP lookup failed', [
                'ip' => $ip,
                'status' => $response->status(),
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Geo IP lookup exception', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Check if IP is whitelisted (for admin access, office IPs, etc.)
     */
    private function isWhitelistedIp(string $ip): bool
    {
        $whitelist = config('security.geo_whitelist_ips', []);
        
        return in_array($ip, $whitelist);
    }
}
