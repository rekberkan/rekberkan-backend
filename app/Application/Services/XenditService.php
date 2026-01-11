<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Models\Deposit;
use App\Models\Withdrawal;
use App\Models\PaymentWebhookLog;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Enums\PaymentMethod;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

final class XenditService
{
    private string $secretKey;
    private string $callbackToken;
    private string $baseUrl;
    private int $webhookDriftSeconds;

    public function __construct()
    {
        $this->secretKey = config('services.xendit.secret_key');
        $this->callbackToken = config('services.xendit.callback_token');
        $this->baseUrl = config('services.xendit.base_url', 'https://api.xendit.co');
        $this->webhookDriftSeconds = (int) config('security.webhook_drift_seconds', 300);
    }

    /**
     * Create deposit payment request
     */
    public function createDeposit(
        int $tenantId,
        int $userId,
        int $walletId,
        int $amount,
        PaymentMethod $method,
        string $idempotencyKey
    ): Deposit {
        return DB::transaction(function () use (
            $tenantId,
            $userId,
            $walletId,
            $amount,
            $method,
            $idempotencyKey
        ) {
            $deposit = Deposit::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'wallet_id' => $walletId,
                'amount' => $amount,
                'payment_method' => $method,
                'status' => PaymentStatus::PENDING,
                'idempotency_key' => $idempotencyKey,
            ]);

            // Call Xendit API based on payment method
            $gatewayResponse = match($method) {
                PaymentMethod::VIRTUAL_ACCOUNT => $this->createVirtualAccount($deposit),
                PaymentMethod::EWALLET => $this->createEWallet($deposit),
                PaymentMethod::QRIS => $this->createQRIS($deposit),
                default => throw new \InvalidArgumentException('Unsupported payment method'),
            };

            $deposit->update([
                'gateway_transaction_id' => $gatewayResponse['id'] ?? null,
                'gateway_reference' => $gatewayResponse['external_id'] ?? null,
                'gateway_response' => $gatewayResponse,
                'status' => PaymentStatus::PROCESSING,
            ]);

            Log::info('Deposit created', [
                'deposit_id' => $deposit->id,
                'amount' => $amount,
                'method' => $method->value,
            ]);

            return $deposit;
        });
    }

    /**
     * Create withdrawal payout
     */
    public function createWithdrawal(
        int $tenantId,
        int $userId,
        int $walletId,
        int $amount,
        string $bankCode,
        string $accountNumber,
        string $accountHolderName,
        string $idempotencyKey
    ): Withdrawal {
        return DB::transaction(function () use (
            $tenantId,
            $userId,
            $walletId,
            $amount,
            $bankCode,
            $accountNumber,
            $accountHolderName,
            $idempotencyKey
        ) {
            $withdrawal = Withdrawal::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'wallet_id' => $walletId,
                'amount' => $amount,
                'bank_code' => $bankCode,
                'account_number' => $accountNumber,
                'account_holder_name' => $accountHolderName,
                'status' => PaymentStatus::PENDING,
                'idempotency_key' => $idempotencyKey,
            ]);

            // Call Xendit Disbursement API
            $gatewayResponse = $this->createDisbursement($withdrawal);

            $withdrawal->update([
                'gateway_transaction_id' => $gatewayResponse['id'] ?? null,
                'gateway_reference' => $gatewayResponse['external_id'] ?? null,
                'gateway_response' => $gatewayResponse,
                'status' => PaymentStatus::PROCESSING,
            ]);

            Log::info('Withdrawal created', [
                'withdrawal_id' => $withdrawal->id,
                'amount' => $amount,
                'bank_code' => $bankCode,
            ]);

            return $withdrawal;
        });
    }

    /**
     * Verify webhook signature using HMAC SHA-256
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $expectedSignature = hash_hmac('sha256', $payload, $this->callbackToken);
        
        // Constant-time comparison to prevent timing attacks
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Validate webhook timestamp (prevent replay attacks)
     */
    public function validateWebhookTimestamp(?string $timestamp): bool
    {
        if (!$timestamp) {
            return false;
        }

        $webhookTime = strtotime($timestamp);
        $currentTime = time();
        $diff = abs($currentTime - $webhookTime);

        return $diff <= $this->webhookDriftSeconds;
    }

    /**
     * Process deposit webhook (idempotent)
     */
    public function processDepositWebhook(array $payload, string $signature, string $ipAddress): void
    {
        $webhookId = $payload['id'] ?? null;
        
        // Check if already processed (idempotency)
        $existing = PaymentWebhookLog::where('webhook_id', $webhookId)->first();
        if ($existing && $existing->processed) {
            Log::info('Webhook already processed', ['webhook_id' => $webhookId]);
            return;
        }

        // Log webhook
        $webhookLog = PaymentWebhookLog::create([
            'webhook_id' => $webhookId,
            'event_type' => 'deposit',
            'payload' => $payload,
            'signature' => $signature,
            'signature_verified' => false,
            'ip_address' => $ipAddress,
        ]);

        try {
            // Verify signature
            $payloadJson = json_encode($payload);
            if (!$this->verifyWebhookSignature($payloadJson, $signature)) {
                throw new \Exception('Invalid webhook signature');
            }

            $webhookLog->update(['signature_verified' => true]);

            // Validate timestamp
            if (!$this->validateWebhookTimestamp($payload['created'] ?? null)) {
                throw new \Exception('Webhook timestamp outside drift window');
            }

            // Find deposit by gateway reference
            $externalId = $payload['external_id'] ?? null;
            $deposit = Deposit::where('gateway_reference', $externalId)->first();

            if (!$deposit) {
                throw new \Exception('Deposit not found for external_id: ' . $externalId);
            }

            // Update deposit status
            $status = $this->mapXenditStatus($payload['status'] ?? 'FAILED');
            
            if ($status === PaymentStatus::COMPLETED) {
                $this->completeDeposit($deposit, $payload);
            } elseif ($status->isFailed()) {
                $deposit->markAsFailed(
                    $payload['failure_code'] ?? 'Unknown error',
                    $payload
                );
            }

            $webhookLog->markAsProcessed();

        } catch (\Throwable $e) {
            $webhookLog->markAsFailed($e->getMessage());
            
            Log::error('Webhook processing failed', [
                'webhook_id' => $webhookId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Complete deposit and update ledger
     */
    private function completeDeposit(Deposit $deposit, array $gatewayResponse): void
    {
        DB::transaction(function () use ($deposit, $gatewayResponse) {
            // Mark deposit as completed
            $deposit->markAsCompleted($gatewayResponse);

            // Create ledger posting (via LedgerService)
            app(LedgerServiceInterface::class)->recordDeposit(
                tenantId: $deposit->tenant_id,
                walletId: $deposit->wallet_id,
                amount: $deposit->amount,
                depositId: $deposit->id,
                idempotencyKey: $deposit->idempotency_key
            );

            Log::info('Deposit completed and ledger updated', [
                'deposit_id' => $deposit->id,
                'amount' => $deposit->amount,
            ]);
        });
    }

    /**
     * Xendit API calls
     */
    private function createVirtualAccount(Deposit $deposit): array
    {
        $response = Http::withBasicAuth($this->secretKey, '')
            ->post($this->baseUrl . '/callback_virtual_accounts', [
                'external_id' => $deposit->id,
                'bank_code' => 'BNI', // Default, should be configurable
                'name' => $deposit->user->name,
                'expected_amount' => $deposit->amount,
                'is_closed' => true,
                'expiration_date' => now()->addHours(24)->toIso8601String(),
            ]);

        return $response->json();
    }

    private function createEWallet(Deposit $deposit): array
    {
        $response = Http::withBasicAuth($this->secretKey, '')
            ->post($this->baseUrl . '/ewallets/charges', [
                'reference_id' => $deposit->id,
                'currency' => 'IDR',
                'amount' => $deposit->amount,
                'checkout_method' => 'ONE_TIME_PAYMENT',
                'channel_code' => 'ID_OVO', // Default
                'channel_properties' => [
                    'mobile_number' => $deposit->user->phone,
                ],
            ]);

        return $response->json();
    }

    private function createQRIS(Deposit $deposit): array
    {
        $response = Http::withBasicAuth($this->secretKey, '')
            ->post($this->baseUrl . '/qr_codes', [
                'external_id' => $deposit->id,
                'type' => 'DYNAMIC',
                'currency' => 'IDR',
                'amount' => $deposit->amount,
            ]);

        return $response->json();
    }

    private function createDisbursement(Withdrawal $withdrawal): array
    {
        $response = Http::withBasicAuth($this->secretKey, '')
            ->post($this->baseUrl . '/disbursements', [
                'external_id' => $withdrawal->id,
                'amount' => $withdrawal->amount,
                'bank_code' => $withdrawal->bank_code,
                'account_holder_name' => $withdrawal->account_holder_name,
                'account_number' => $withdrawal->account_number,
                'description' => 'Withdrawal from Rekberkan',
            ]);

        return $response->json();
    }

    private function mapXenditStatus(string $xenditStatus): PaymentStatus
    {
        return match(strtoupper($xenditStatus)) {
            'ACTIVE', 'PAID', 'SUCCEEDED', 'COMPLETED' => PaymentStatus::COMPLETED,
            'PENDING' => PaymentStatus::PROCESSING,
            'INACTIVE', 'EXPIRED' => PaymentStatus::EXPIRED,
            'FAILED' => PaymentStatus::FAILED,
            default => PaymentStatus::FAILED,
        };
    }
}
