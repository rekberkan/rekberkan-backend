<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SecurityEventLogger
{
    /**
     * Log a security event for audit trail.
     */
    public function log(
        string $eventType,
        ?string $userId = null,
        ?string $tenantId = null,
        ?array $metadata = null,
        string $severity = 'low',
        bool $isSuspicious = false
    ): void {
        $request = request();

        DB::table('security_events')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId ?? $request->attributes->get('tenant_id'),
            'user_id' => $userId ?? $request->user()?->id,
            'event_type' => $eventType,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device_id' => $request->attributes->get('device_id'),
            'country_code' => $this->resolveCountryCode($request),
            'metadata' => $metadata ? json_encode($metadata) : null,
            'severity' => $severity,
            'is_suspicious' => $isSuspicious,
            'created_at' => now(),
        ]);

        // Log to security channel for real-time monitoring
        \Illuminate\Support\Facades\Log::channel('security')->info(
            "Security event: {$eventType}",
            [
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'severity' => $severity,
                'is_suspicious' => $isSuspicious,
                'metadata' => $metadata,
            ]
        );
    }

    /**
     * Resolve country code from IP address using GeoIP service.
     * 
     * SECURITY FIX: Implemented GeoIP integration for location-based risk analysis.
     * Uses free ipapi.co service with caching to reduce API calls.
     * 
     * Alternative: Use MaxMind GeoLite2 or IP2Location for production.
     */
    private function resolveCountryCode(Request $request): ?string
    {
        $ipAddress = $request->ip();
        
        // Skip for local/private IPs
        if ($this->isPrivateIP($ipAddress)) {
            return null;
        }

        // Check cache first (cache for 24 hours)
        $cacheKey = "geoip:" . $ipAddress;
        
        return Cache::remember($cacheKey, 86400, function () use ($ipAddress) {
            try {
                // Using free ipapi.co service (150 requests/min limit)
                // For production, consider MaxMind GeoLite2 or IP2Location
                $response = Http::timeout(2)
                    ->retry(2, 100)
                    ->get("https://ipapi.co/{$ipAddress}/country/");

                if ($response->successful()) {
                    $countryCode = trim($response->body());
                    
                    // Validate country code format (2-letter ISO code)
                    if (preg_match('/^[A-Z]{2}$/', $countryCode)) {
                        return $countryCode;
                    }
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning(
                    'GeoIP lookup failed',
                    ['ip' => $ipAddress, 'error' => $e->getMessage()]
                );
            }

            return null;
        });
    }

    /**
     * Check if IP is private/local.
     */
    private function isPrivateIP(string $ip): bool
    {
        // Local/private IP ranges
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * Detect suspicious activity patterns.
     */
    public function detectSuspiciousActivity(string $userId, string $eventType): bool
    {
        // Check for rapid repeated attempts
        $recentAttempts = DB::table('security_events')
            ->where('user_id', $userId)
            ->where('event_type', $eventType)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->count();

        return $recentAttempts > 5;
    }
}
