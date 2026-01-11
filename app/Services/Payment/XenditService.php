<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Deposit;
use App\Services\Security\SecurityEventLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class XenditService
{
    private const WEBHOOK_TIMESTAMP_TOLERANCE = 300; // 5 minutes
    
    // Xendit IP whitelist (updated regularly from docs)
    private const XENDIT_IPS = [
        '169.254.9.1',
        '169.254.10.1',
        // Add more Xendit IPs from their documentation
    ];

    public function __construct(
        private SecurityEventLogger $securityLogger
    ) {}

    /**
     * Process deposit webhook from Xendit.
     * 
     * SECURITY FIXES:
     * 1. Added webhook timestamp validation to prevent replay attacks
     * 2. IP whitelist check for callback source
     * 3. Enhanced logging for suspicious webhook attempts
     */
    public function processDepositWebhook(Request $request): array
    {
        $payload = $request->all();
        $signature = $request->header('x-callback-token');
        $timestamp = $request->header('x-timestamp');
        $ipAddress = $request->ip();

        // Step 1: Verify IP whitelist (if enabled)
        if (config('payment.xendit.verify_ip', false)) {
            if (!$this->verifyWebhookIP($ipAddress)) {
                $this->logSuspiciousWebhook('invalid_ip', $ipAddress, $payload);
                
                return [
                    'success' => false,
                    'error' => 'Invalid source IP',
                ];
            }
        }

        // Step 2: Verify timestamp (prevent replay attacks)
        if (!$this->validateWebhookTimestamp($timestamp)) {
            $this->logSuspiciousWebhook('invalid_timestamp', $ipAddress, $payload);
            
            return [
                'success' => false,
                'error' => 'Webhook timestamp expired or invalid',
            ];
        }

        // Step 3: Verify HMAC signature
        if (!$this->verifyWebhookSignature($signature, $payload)) {
            $this->logSuspiciousWebhook('invalid_signature', $ipAddress, $payload);
            
            return [
                'success' => false,
                'error' => 'Invalid webhook signature',
            ];
        }

        // Step 4: Check idempotency (prevent duplicate processing)
        $webhookId = $payload['id'] ?? null;
        if ($this->isDuplicateWebhook($webhookId)) {
            Log::info('Duplicate webhook ignored', ['webhook_id' => $webhookId]);
            
            return [
                'success' => true,
                'message' => 'Webhook already processed',
            ];
        }

        // Step 5: Process deposit
        return $this->processDeposit($payload);
    }

    /**
     * Validate webhook timestamp to prevent replay attacks.
     * Rejects webhooks with timestamp > 5 minutes old.
     */
    private function validateWebhookTimestamp(?string $timestamp): bool
    {
        if (empty($timestamp)) {
            return false;
        }

        try {
            $webhookTime = strtotime($timestamp);
            $currentTime = time();
            
            // Reject if timestamp is in the future or too old
            if ($webhookTime > $currentTime) {
                return false;
            }
            
            $timeDifference = $currentTime - $webhookTime;
            
            return $timeDifference <= self::WEBHOOK_TIMESTAMP_TOLERANCE;
        } catch (\Exception $e) {
            Log::warning('Invalid webhook timestamp format', [
                'timestamp' => $timestamp,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Verify webhook source IP against whitelist.
     */
    private function verifyWebhookIP(string $ipAddress): bool
    {
        return in_array($ipAddress, self::XENDIT_IPS, true);
    }

    /**
     * Verify webhook signature using HMAC-SHA256.
     */
    private function verifyWebhookSignature(?string $signature, array $payload): bool
    {
        if (empty($signature)) {
            return false;
        }

        $webhookSecret = config('payment.xendit.webhook_secret');
        $payloadString = json_encode($payload, JSON_THROW_ON_ERROR);
        
        $expectedSignature = hash_hmac('sha256', $payloadString, $webhookSecret);
        
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Check if webhook has already been processed.
     */
    private function isDuplicateWebhook(?string $webhookId): bool
    {
        if (empty($webhookId)) {
            return false;
        }

        return DB::table('deposits')
            ->where('webhook_id', $webhookId)
            ->exists();
    }

    /**
     * Process the actual deposit.
     */
    private function processDeposit(array $payload): array
    {
        try {
            DB::beginTransaction();

            // Extract deposit data from payload
            $depositData = [
                'webhook_id' => $payload['id'],
                'external_id' => $payload['external_id'] ?? null,
                'amount' => $payload['amount'] ?? 0,
                'status' => $payload['status'] ?? 'pending',
                'payment_method' => $payload['payment_method'] ?? null,
                'metadata' => json_encode($payload),
                'processed_at' => now(),
            ];

            // Create or update deposit record
            $deposit = Deposit::updateOrCreate(
                ['webhook_id' => $depositData['webhook_id']],
                $depositData
            );

            // Log security event
            $this->securityLogger->log(
                'deposit.webhook.processed',
                $deposit->user_id ?? null,
                null,
                ['deposit_id' => $deposit->id, 'amount' => $deposit->amount],
                'low',
                false
            );

            DB::commit();

            return [
                'success' => true,
                'deposit_id' => $deposit->id,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Deposit processing failed', [
                'payload' => $payload,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Deposit processing failed',
            ];
        }
    }

    /**
     * Log suspicious webhook attempts.
     */
    private function logSuspiciousWebhook(
        string $reason,
        string $ipAddress,
        array $payload
    ): void {
        $this->securityLogger->log(
            'deposit.webhook.suspicious',
            null,
            null,
            [
                'reason' => $reason,
                'ip_address' => $ipAddress,
                'payload' => $payload,
            ],
            'high',
            true
        );

        Log::warning('Suspicious webhook attempt', [
            'reason' => $reason,
            'ip' => $ipAddress,
        ]);
    }
}
