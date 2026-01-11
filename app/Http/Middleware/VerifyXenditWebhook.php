<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Services\XenditService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware: Verify Xendit Webhook Signature and IP
 * 
 * Security features:
 * - HMAC SHA-256 signature verification
 * - IP whitelist validation (if enabled)
 * - Replay attack prevention via timestamp
 */
class VerifyXenditWebhook
{
    private const XENDIT_IP_RANGES = [
        '147.139.160.0/22',  // Xendit production IPs
        '103.208.22.0/24',
    ];

    public function __construct(
        private XenditService $xenditService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Callback-Token');
        $rawPayload = $request->getContent();
        $ipAddress = $request->ip();

        // Verify IP whitelist if enabled
        if (config('services.xendit.verify_ip', true)) {
            if (!$this->isFromXendit($ipAddress)) {
                Log::warning('Webhook from unauthorized IP', [
                    'ip' => $ipAddress,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized IP address',
                ], 403);
            }
        }

        // Verify signature
        if (!$signature || !$this->xenditService->verifyWebhookSignature($rawPayload, $signature)) {
            Log::warning('Invalid webhook signature', [
                'ip' => $ipAddress,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid signature',
            ], 401);
        }

        // Verify timestamp to prevent replay attacks
        $payload = json_decode($rawPayload, true);
        if (!$this->xenditService->validateWebhookTimestamp($payload['created'] ?? null)) {
            Log::warning('Webhook timestamp outside drift window', [
                'ip' => $ipAddress,
                'timestamp' => $payload['created'] ?? 'none',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Timestamp outside acceptable range',
            ], 400);
        }

        return $next($request);
    }

    /**
     * Check if IP is from Xendit
     */
    private function isFromXendit(string $ip): bool
    {
        foreach (self::XENDIT_IP_RANGES as $range) {
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
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }

        [$subnet, $bits] = explode('/', $range);
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - (int)$bits);
        $subnet &= $mask;

        return ($ip & $mask) === $subnet;
    }
}
