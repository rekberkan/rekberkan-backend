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
 * - Timestamp validation (FIRST - prevents replay attacks)
 * - IP whitelist validation (if enabled)
 * - HMAC SHA-256 signature verification
 * 
 * SECURITY FIX: Bug #1 - Timestamp validation moved before signature check
 * to prevent replay attack vulnerabilities
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

        // SECURITY FIX: Validate timestamp FIRST to prevent replay attacks
        // Parse payload early to check timestamp before expensive signature verification
        $payload = json_decode($rawPayload, true);
        
        if (!$payload || !isset($payload['created'])) {
            Log::warning('Webhook missing timestamp', [
                'ip' => $ipAddress,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid payload structure',
            ], 400);
        }

        // Verify timestamp to prevent replay attacks (MOVED UP)
        if (!$this->xenditService->validateWebhookTimestamp($payload['created'])) {
            Log::warning('Webhook timestamp outside drift window', [
                'ip' => $ipAddress,
                'timestamp' => $payload['created'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Timestamp outside acceptable range',
            ], 400);
        }

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

        // Verify signature (AFTER timestamp and IP checks)
        if (!$signature || !$this->xenditService->verifyWebhookSignature($rawPayload, $signature)) {
            Log::warning('Invalid webhook signature', [
                'ip' => $ipAddress,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid signature',
            ], 401);
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
