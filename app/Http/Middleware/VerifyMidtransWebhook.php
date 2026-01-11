<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware: Verify Midtrans Webhook Signature
 */
class VerifyMidtransWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $serverKey = config('services.midtrans.server_key');
        $orderId = $request->input('order_id');
        $statusCode = $request->input('status_code');
        $grossAmount = $request->input('gross_amount');
        $receivedSignature = $request->input('signature_key');
        $whitelist = config('services.midtrans.ip_whitelist', []);

        if (is_array($whitelist) && !empty($whitelist) && !in_array($request->ip(), $whitelist, true)) {
            Log::warning('Midtrans webhook from unauthorized IP', [
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unauthorized IP address',
            ], 403);
        }

        // Calculate expected signature
        $expectedSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

        // Verify signature using constant-time comparison
        if (
            empty($receivedSignature)
            || strlen($receivedSignature) !== strlen($expectedSignature)
            || !hash_equals($expectedSignature, $receivedSignature)
        ) {
            Log::warning('Invalid Midtrans webhook signature', [
                'ip' => $request->ip(),
                'order_id' => $orderId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid signature',
            ], 401);
        }

        return $next($request);
    }
}
