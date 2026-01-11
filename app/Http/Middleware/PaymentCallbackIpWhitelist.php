<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PaymentCallbackIpWhitelist
{
    /**
     * IP ranges for payment gateways
     * Source: Official documentation from Midtrans & Xendit
     */
    private const PAYMENT_GATEWAY_IPS = [
        // Midtrans IP ranges
        'midtrans' => [
            '103.127.16.0/23',   // Midtrans production
            '103.208.23.0/24',   // Midtrans production
            '103.127.17.6',      // Specific Midtrans IP
        ],
        // Xendit IP ranges
        'xendit' => [
            '18.141.95.53',      // Xendit SG
            '54.255.215.155',    // Xendit SG
            '13.229.135.175',    // Xendit SG
            '18.141.95.85',      // Xendit SG
        ],
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $gateway = null): Response
    {
        $clientIp = $this->getClientIp($request);
        $allowedIps = $this->getAllowedIps($gateway);

        // In development, allow localhost
        if (app()->environment('local') && $this->isLocalhost($clientIp)) {
            return $next($request);
        }

        // Check IP whitelist
        if (!$this->isIpAllowed($clientIp, $allowedIps)) {
            Log::critical('Payment callback from unauthorized IP', [
                'ip' => $clientIp,
                'gateway' => $gateway,
                'path' => $request->path(),
                'payload' => $request->except(['password', 'secret']),
            ]);

            return response()->json([
                'error' => 'Forbidden',
                'message' => 'Request from unauthorized IP address',
            ], 403);
        }

        return $next($request);
    }

    /**
     * Get client IP address
     */
    private function getClientIp(Request $request): string
    {
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
     * Get allowed IPs for specific gateway or all
     */
    private function getAllowedIps(?string $gateway): array
    {
        if ($gateway && isset(self::PAYMENT_GATEWAY_IPS[$gateway])) {
            return self::PAYMENT_GATEWAY_IPS[$gateway];
        }

        // Return all gateway IPs if no specific gateway specified
        return array_merge(...array_values(self::PAYMENT_GATEWAY_IPS));
    }

    /**
     * Check if IP is allowed
     */
    private function isIpAllowed(string $ip, array $allowedIps): bool
    {
        foreach ($allowedIps as $range) {
            if ($ip === $range) {
                return true;
            }

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

    /**
     * Check if IP is localhost
     */
    private function isLocalhost(string $ip): bool
    {
        return in_array($ip, ['127.0.0.1', '::1', 'localhost']);
    }
}
