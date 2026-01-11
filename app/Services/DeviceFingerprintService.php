<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class DeviceFingerprintService
{
    /**
     * Generate secure device fingerprint with multiple signals
     */
    public function generate(Request $request): string
    {
        $components = [
            // Browser signals
            $this->normalizeUserAgent($request->userAgent()),
            $request->header('Accept-Language'),
            $request->header('Accept-Encoding'),
            $request->header('Accept'),
            
            // Client hints (more reliable than UA)
            $request->header('Sec-CH-UA'),
            $request->header('Sec-CH-UA-Mobile'),
            $request->header('Sec-CH-UA-Platform'),
            
            // Network signals
            $this->getIpNetwork($request->ip()),
            
            // TLS fingerprint
            $request->header('SSL_CIPHER'),
            $request->header('SSL_PROTOCOL'),
        ];

        // Filter out null values
        $components = array_filter($components);

        return hash('sha256', implode('|', $components));
    }

    /**
     * Verify device fingerprint matches
     */
    public function verify(string $storedFingerprint, Request $request): bool
    {
        $currentFingerprint = $this->generate($request);
        return hash_equals($storedFingerprint, $currentFingerprint);
    }

    /**
     * Calculate fingerprint similarity score (0-100)
     */
    public function similarity(string $fingerprint1, string $fingerprint2): int
    {
        // Exact match
        if (hash_equals($fingerprint1, $fingerprint2)) {
            return 100;
        }

        // For hash comparison, use hamming distance approximation
        $diff = 0;
        $len = min(strlen($fingerprint1), strlen($fingerprint2));
        
        for ($i = 0; $i < $len; $i++) {
            if ($fingerprint1[$i] !== $fingerprint2[$i]) {
                $diff++;
            }
        }

        return max(0, 100 - (int) (($diff / $len) * 100));
    }

    /**
     * Normalize user agent to reduce noise
     */
    private function normalizeUserAgent(?string $userAgent): ?string
    {
        if (!$userAgent) {
            return null;
        }

        // Remove version numbers for more stable fingerprint
        $normalized = preg_replace('/\d+\.\d+\.\d+/', 'X.X.X', $userAgent);
        
        return strtolower($normalized);
    }

    /**
     * Get IP network (subnet) instead of exact IP
     */
    private function getIpNetwork(string $ip): string
    {
        // For IPv4, use /24 subnet
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return implode('.', array_slice($parts, 0, 3)) . '.0/24';
        }

        // For IPv6, use /64 subnet
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            return implode(':', array_slice($parts, 0, 4)) . '::/64';
        }

        return 'unknown';
    }
}
