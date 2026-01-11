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

        // Calculate expected signature
        $expectedSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

        // Verify signature using constant-time comparison
        if (!hash_equals($expectedSignature, $receivedSignature)) {
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
