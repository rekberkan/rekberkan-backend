<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     * Resolve country code from IP (stub for GeoIP integration).
     */
    private function resolveCountryCode(Request $request): ?string
    {
        // TODO: Integrate with GeoIP service (MaxMind, IP2Location, etc.)
        return null;
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
