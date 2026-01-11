<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Escrow\Services\EscrowService;
use App\Infrastructure\Payment\MidtransGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(
        private MidtransGateway $midtrans,
        private EscrowService $escrowService
    ) {}

    /**
     * Handle Midtrans payment notification.
     */
    public function midtransNotification(Request $request)
    {
        try {
            $payload = $request->all();
            $signature = $payload['signature_key'] ?? '';

            // Verify signature
            if (!$this->midtrans->verifyWebhookSignature($payload, $signature)) {
                Log::warning('Invalid Midtrans webhook signature', ['payload' => $payload]);
                return response()->json(['message' => 'Invalid signature'], 401);
            }

            // Log webhook
            $this->logWebhook('midtrans', $payload);

            $orderId = $payload['order_id'] ?? null;
            $transactionStatus = $payload['transaction_status'] ?? null;

            if (!$orderId || !$transactionStatus) {
                Log::warning('Midtrans webhook missing required fields', [
                    'order_id' => $orderId,
                    'transaction_status' => $transactionStatus,
                ]);

                return response()->json(['message' => 'Missing required fields'], 422);
            }
            $fraudStatus = $payload['fraud_status'] ?? 'accept';

            // Get deposit record
            $deposit = DB::table('deposits')->where('order_id', $orderId)->first();

            if (!$deposit) {
                Log::error('Deposit not found for order', ['order_id' => $orderId]);
                return response()->json(['message' => 'Order not found'], 404);
            }

            // Process based on status
            if ($transactionStatus === 'capture' || $transactionStatus === 'settlement') {
                if ($fraudStatus === 'accept') {
                    $this->processSuccessfulDeposit($deposit);
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

            return response()->json(['message' => 'Internal error'], 500);
        }
    }

    /**
     * Process successful deposit.
     */
    private function processSuccessfulDeposit(object $deposit): void
    {
        DB::transaction(function () use ($deposit) {
            if (($deposit->status ?? null) === 'completed') {
                Log::info('Deposit already completed, skipping credit', [
                    'deposit_id' => $deposit->id,
                ]);
                return;
            }

            // Update deposit status
            DB::table('deposits')->where('id', $deposit->id)->update([
                'status' => 'completed',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

            // Credit user wallet
            DB::table('account_balances')
                ->where('account_id', $deposit->wallet_id)
                ->increment('balance', $deposit->amount);

            // If this is for escrow funding, trigger escrow fund
            if ($deposit->escrow_id) {
                $this->escrowService->fund(
                    escrowId: $deposit->escrow_id,
                    idempotencyKey: "deposit-{$deposit->id}"
                );
            }

            Log::info('Deposit completed', ['deposit_id' => $deposit->id]);
        });
    }

    /**
     * Process failed deposit.
     */
    private function processFailedDeposit(object $deposit, string $reason): void
    {
        DB::table('deposits')->where('id', $deposit->id)->update([
            'status' => 'failed',
            'failure_reason' => $reason,
            'updated_at' => now(),
        ]);

        Log::info('Deposit failed', ['deposit_id' => $deposit->id, 'reason' => $reason]);
    }

    /**
     * Process pending deposit.
     */
    private function processPendingDeposit(object $deposit): void
    {
        DB::table('deposits')->where('id', $deposit->id)->update([
            'status' => 'pending',
            'updated_at' => now(),
        ]);
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
