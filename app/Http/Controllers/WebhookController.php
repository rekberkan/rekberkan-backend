<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Deposit;
use App\Models\Wallet;
use App\Infrastructure\Payment\MidtransGateway;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    // IP whitelist for payment gateways
    private const ALLOWED_IPS = [
        // Midtrans IPs
        '103.127.16.0/23',
        '103.127.18.0/23',
        '103.208.23.0/24',
        
        // Xendit IPs (example)
        '54.251.0.0/16',
        
        // Localhost for development
        '127.0.0.1',
        '::1',
    ];

    public function __construct(
        private MidtransGateway $midtrans,
        private LedgerService $ledgerService
    ) {}

    /**
     * Handle Midtrans payment notification.
     */
    public function midtransNotification(Request $request)
    {
        try {
            // Validate IP whitelist
            if (!$this->isIpAllowed($request->ip())) {
                Log::warning('Webhook from unauthorized IP', [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $payload = $request->all();
            $signature = $payload['signature_key'] ?? '';

            // Guard: Check required fields first
            $orderId = $payload['order_id'] ?? null;
            $transactionStatus = $payload['transaction_status'] ?? null;

            if (!$orderId || !$transactionStatus) {
                Log::warning('Midtrans webhook missing required fields', [
                    'payload' => $payload,
                ]);
                return response()->json(['message' => 'Missing required fields'], 422);
            }

            // Verify signature
            if (!$this->midtrans->verifyWebhookSignature($payload, $signature)) {
                Log::warning('Invalid Midtrans webhook signature', [
                    'order_id' => $orderId,
                    'ip' => $request->ip(),
                ]);
                return response()->json(['message' => 'Invalid signature'], 401);
            }

            // Log webhook
            $this->logWebhook('midtrans', $payload);

            $fraudStatus = $payload['fraud_status'] ?? 'accept';

            // Get deposit record with tenant context
            $deposit = Deposit::with(['wallet', 'user'])
                ->where('payment_order_id', $orderId)
                ->first();

            if (!$deposit) {
                Log::error('Deposit not found for order', ['order_id' => $orderId]);
                return response()->json(['message' => 'Order not found'], 404);
            }

            // Idempotency check - if already completed, return OK
            if ($deposit->status === 'completed') {
                Log::info('Deposit already completed (idempotent)', [
                    'deposit_id' => $deposit->id,
                    'order_id' => $orderId,
                ]);
                return response()->json(['message' => 'OK']);
            }

            // Process based on status
            if ($transactionStatus === 'capture' || $transactionStatus === 'settlement') {
                if ($fraudStatus === 'accept') {
                    $this->processSuccessfulDeposit($deposit);
                } else {
                    Log::warning('Payment flagged as fraud', [
                        'deposit_id' => $deposit->id,
                        'fraud_status' => $fraudStatus,
                    ]);
                    $this->processFailedDeposit($deposit, 'fraud_detected');
                }
            } elseif ($transactionStatus === 'cancel' || $transactionStatus === 'deny' || $transactionStatus === 'expire') {
                $this->processFailedDeposit($deposit, $transactionStatus);
            } elseif ($transactionStatus === 'pending') {
                $this->processPendingDeposit($deposit);
            }

            return response()->json(['message' => 'OK']);
        } catch (\Exception $e) {
            Log::error('Midtrans webhook processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Don't expose internal error details
            return response()->json(['message' => 'Processing error'], 500);
        }
    }

    /**
     * Process successful deposit using LedgerService.
     */
    private function processSuccessfulDeposit(Deposit $deposit): void
    {
        DB::transaction(function () use ($deposit) {
            // Lock deposit for update (prevents race condition)
            $deposit = Deposit::where('id', $deposit->id)->lockForUpdate()->first();
            
            // Double-check idempotency inside transaction
            if ($deposit->status === 'completed') {
                Log::info('Deposit already completed in race condition check', [
                    'deposit_id' => $deposit->id,
                ]);
                return;
            }

            // Use LedgerService for proper double-entry accounting
            $this->ledgerService->creditWallet(
                $deposit->wallet,
                $deposit->amount,
                'deposit',
                "Deposit completed: {$deposit->payment_order_id}",
                [
                    'deposit_id' => $deposit->id,
                    'order_id' => $deposit->payment_order_id,
                ]
            );

            // Update deposit status
            $deposit->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            Log::info('Deposit completed successfully', [
                'deposit_id' => $deposit->id,
                'user_id' => $deposit->user_id,
                'tenant_id' => $deposit->tenant_id,
                'amount' => $deposit->amount,
            ]);
        });
    }

    /**
     * Process failed deposit.
     */
    private function processFailedDeposit(Deposit $deposit, string $reason): void
    {
        $deposit->update([
            'status' => 'failed',
            'failure_reason' => $reason,
        ]);

        Log::info('Deposit failed', [
            'deposit_id' => $deposit->id,
            'reason' => $reason,
        ]);
    }

    /**
     * Process pending deposit.
     */
    private function processPendingDeposit(Deposit $deposit): void
    {
        // Only update if not already completed
        if ($deposit->status !== 'completed') {
            $deposit->update([
                'status' => 'pending',
            ]);
        }
    }

    /**
     * Check if IP is in whitelist.
     */
    private function isIpAllowed(string $ip): bool
    {
        // Allow in local development
        if (app()->environment('local')) {
            return true;
        }

        foreach (self::ALLOWED_IPS as $allowedRange) {
            if ($this->ipInRange($ip, $allowedRange)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if IP is in CIDR range.
     */
    private function ipInRange(string $ip, string $range): bool
    {
        // If no CIDR notation, exact match
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }

        [$subnet, $mask] = explode('/', $range);
        
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskLong = -1 << (32 - (int) $mask);
        
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }

    /**
     * Log webhook for audit trail.
     */
    private function logWebhook(string $provider, array $payload): void
    {
        DB::table('webhook_logs')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'provider' => $provider,
            'payload' => json_encode($payload),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }
}
