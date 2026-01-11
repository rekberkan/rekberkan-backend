<?php

declare(strict_types=1);

namespace App\Domain\Escrow\Services;

use App\Domain\Escrow\Enums\EscrowStatus;
use App\Domain\Financial\Enums\ProcessingCode;
use App\Domain\Financial\Enums\ReasonCode;
use App\Domain\Financial\Enums\MessagePhase;
use App\Domain\Financial\Services\PostingService;
use App\Domain\Financial\ValueObjects\Money;
use App\Domain\Ledger\ValueObjects\RRN;
use App\Domain\Ledger\ValueObjects\STAN;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EscrowService
{
    public function __construct(
        private PostingService $postingService
    ) {}

    /**
     * Create escrow transaction.
     * 
     * NOTE: Field names aligned with Eloquent model:
     * - buyer_id (not sender_id)
     * - seller_id (not recipient_id)
     * - amount (base amount, no platform_fee/total_amount in model)
     */
    public function create(
        string $tenantId,
        string $buyerId,
        string $sellerId,
        Money $amount,
        string $description,
        ?int $expiresInDays = 30
    ): array {
        return DB::transaction(function () use ($tenantId, $buyerId, $sellerId, $amount, $description, $expiresInDays) {
            $escrowId = Str::uuid()->toString();
            
            // Calculate fees (2% platform fee) - but DON'T store in DB
            $platformFee = $amount->multiply('0.02');
            $totalAmount = $amount->add($platformFee);

            // Create escrow record - ONLY fields that exist in model
            DB::table('escrows')->insert([
                'id' => $escrowId,
                'tenant_id' => $tenantId,
                'buyer_id' => $buyerId,      // Fixed: was sender_id
                'seller_id' => $sellerId,    // Fixed: was recipient_id
                'amount' => $amount->getMinorUnits(),
                // Removed: platform_fee, total_amount (not in model)
                'description' => $description,
                'status' => EscrowStatus::PENDING_PAYMENT->value,
                'expires_at' => now()->addDays($expiresInDays),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Create timeline entry with correct structure
            $this->addTimelineEntry($escrowId, 'ESCROW_CREATED', null, [
                'status' => EscrowStatus::PENDING_PAYMENT->value,
                'message' => 'Escrow created',
            ]);

            return [
                'escrow_id' => $escrowId,
                'status' => EscrowStatus::PENDING_PAYMENT->value,
                'amount' => $amount,
                'platform_fee' => $platformFee,  // Return but don't store
                'total_amount' => $totalAmount,   // Return but don't store
            ];
        });
    }

    /**
     * Fund escrow (lock funds from buyer).
     */
    public function fund(string $escrowId, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($escrowId, $idempotencyKey) {
            $escrow = $this->getEscrow($escrowId);

            // Validate state transition
            $currentStatus = EscrowStatus::from($escrow->status);
            if (!$currentStatus->canTransitionTo(EscrowStatus::FUNDED)) {
                throw new \Exception("Cannot fund escrow in status: {$escrow->status}");
            }

            // Calculate total with fee
            $amount = Money::fromMinorUnits($escrow->amount, 'IDR');
            $platformFee = $amount->multiply('0.02');
            $totalAmount = $amount->add($platformFee);

            // Create financial message
            $messageId = $this->createFinancialMessage(
                $escrow->tenant_id,
                ProcessingCode::ESCROW_LOCK,
                $totalAmount->getMinorUnits()
            );

            // Get buyer and platform wallet accounts
            $buyerWallet = $this->getWalletAccount($escrow->buyer_id);
            $platformWallet = $this->getPlatformWallet();

            // Post ledger entries (debit buyer, credit escrow + platform fee)
            $this->postingService->post(
                messageId: $messageId,
                tenantId: $escrow->tenant_id,
                processingCode: ProcessingCode::ESCROW_LOCK,
                entries: [
                    [
                        'account_id' => $buyerWallet,
                        'type' => 'debit',
                        'amount' => $totalAmount->getMinorUnits(),
                        'description' => "Escrow lock: {$escrow->description}",
                    ],
                    [
                        'account_id' => $escrowId, // Escrow account
                        'type' => 'credit',
                        'amount' => $amount->getMinorUnits(),
                        'description' => "Escrow funds: {$escrow->description}",
                    ],
                    [
                        'account_id' => $platformWallet,
                        'type' => 'credit',
                        'amount' => $platformFee->getMinorUnits(),
                        'description' => 'Platform fee',
                    ],
                ],
                idempotencyKey: $idempotencyKey
            );

            // Update escrow status
            $this->updateStatus($escrowId, EscrowStatus::FUNDED, 'ESCROW_FUNDED', null);

            return [
                'escrow_id' => $escrowId,
                'status' => EscrowStatus::FUNDED->value,
                'message' => 'Escrow funded successfully',
            ];
        });
    }

    /**
     * Mark escrow as in progress (seller confirms).
     */
    public function markInProgress(string $escrowId, string $userId): array
    {
        return DB::transaction(function () use ($escrowId, $userId) {
            $escrow = $this->getEscrow($escrowId);

            // Authorization check
            $this->verifyUserIsSeller($escrow, $userId);

            $currentStatus = EscrowStatus::from($escrow->status);
            if (!$currentStatus->canTransitionTo(EscrowStatus::IN_PROGRESS)) {
                throw new \Exception("Cannot mark in progress from status: {$escrow->status}");
            }

            $this->updateStatus($escrowId, EscrowStatus::IN_PROGRESS, 'WORK_STARTED', $userId);

            return [
                'escrow_id' => $escrowId,
                'status' => EscrowStatus::IN_PROGRESS->value,
            ];
        });
    }

    /**
     * Mark as delivered (seller delivers goods/service).
     */
    public function markDelivered(string $escrowId, string $userId, ?string $proofUrl = null): array
    {
        return DB::transaction(function () use ($escrowId, $userId, $proofUrl) {
            $escrow = $this->getEscrow($escrowId);

            // Authorization check
            $this->verifyUserIsSeller($escrow, $userId);

            $currentStatus = EscrowStatus::from($escrow->status);
            if (!$currentStatus->canTransitionTo(EscrowStatus::DELIVERED)) {
                throw new \Exception("Cannot mark delivered from status: {$escrow->status}");
            }

            $this->updateStatus($escrowId, EscrowStatus::DELIVERED, 'GOODS_DELIVERED', $userId, [
                'proof_url' => $proofUrl,
            ]);

            // Set auto-release timer (e.g., 3 days)
            DB::table('escrows')->where('id', $escrowId)->update([
                'auto_release_at' => now()->addDays(3),
            ]);

            return [
                'escrow_id' => $escrowId,
                'status' => EscrowStatus::DELIVERED->value,
                'auto_release_at' => now()->addDays(3),
            ];
        });
    }

    /**
     * Release funds to seller.
     */
    public function release(string $escrowId, string $userId, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($escrowId, $userId, $idempotencyKey) {
            $escrow = $this->getEscrow($escrowId);

            // Authorization check: buyer, seller, or admin
            $this->verifyUserCanRelease($escrow, $userId);

            $currentStatus = EscrowStatus::from($escrow->status);
            if (!$currentStatus->canRelease()) {
                throw new \Exception("Cannot release from status: {$escrow->status}");
            }

            // Create financial message
            $messageId = $this->createFinancialMessage(
                $escrow->tenant_id,
                ProcessingCode::ESCROW_RELEASE,
                $escrow->amount
            );

            // Get seller wallet
            $sellerWallet = $this->getWalletAccount($escrow->seller_id);

            // Post ledger entries (debit escrow, credit seller)
            $this->postingService->post(
                messageId: $messageId,
                tenantId: $escrow->tenant_id,
                processingCode: ProcessingCode::ESCROW_RELEASE,
                entries: [
                    [
                        'account_id' => $escrowId,
                        'type' => 'debit',
                        'amount' => $escrow->amount,
                        'description' => 'Escrow release',
                    ],
                    [
                        'account_id' => $sellerWallet,
                        'type' => 'credit',
                        'amount' => $escrow->amount,
                        'description' => 'Payment received',
                    ],
                ],
                idempotencyKey: $idempotencyKey
            );

            $this->updateStatus($escrowId, EscrowStatus::COMPLETED, 'FUNDS_RELEASED', $userId);

            return [
                'escrow_id' => $escrowId,
                'status' => EscrowStatus::COMPLETED->value,
                'message' => 'Funds released successfully',
            ];
        });
    }

    /**
     * Refund to buyer.
     */
    public function refund(
        string $escrowId,
        string $userId,
        string $reason,
        string $idempotencyKey,
        bool $isAutoRefund = false
    ): array {
        return DB::transaction(function () use ($escrowId, $userId, $reason, $idempotencyKey, $isAutoRefund) {
            $escrow = $this->getEscrow($escrowId);

            // Authorization check: buyer, seller, or admin
            if (!$isAutoRefund) {
                $this->verifyUserCanRefund($escrow, $userId);
            }

            $currentStatus = EscrowStatus::from($escrow->status);
            if (!$currentStatus->canRefund()) {
                throw new \Exception("Cannot refund from status: {$escrow->status}");
            }

            // Create financial message
            $messageId = $this->createFinancialMessage(
                $escrow->tenant_id,
                ProcessingCode::ESCROW_REFUND,
                $escrow->amount
            );

            $buyerWallet = $this->getWalletAccount($escrow->buyer_id);

            // Post ledger entries (debit escrow, credit buyer)
            $this->postingService->post(
                messageId: $messageId,
                tenantId: $escrow->tenant_id,
                processingCode: ProcessingCode::ESCROW_REFUND,
                entries: [
                    [
                        'account_id' => $escrowId,
                        'type' => 'debit',
                        'amount' => $escrow->amount,
                        'description' => 'Escrow refund',
                    ],
                    [
                        'account_id' => $buyerWallet,
                        'type' => 'credit',
                        'amount' => $escrow->amount,
                        'description' => 'Refund received',
                    ],
                ],
                idempotencyKey: $idempotencyKey
            );

            $this->updateStatus(
                $escrowId,
                EscrowStatus::REFUNDED,
                'ESCROW_REFUNDED',
                $isAutoRefund ? null : $userId,
                ['reason' => $reason]
            );

            return [
                'escrow_id' => $escrowId,
                'status' => EscrowStatus::REFUNDED->value,
                'message' => 'Funds refunded successfully',
            ];
        });
    }

    /**
     * Create dispute.
     */
    public function createDispute(string $escrowId, string $userId, string $reason): array
    {
        return DB::transaction(function () use ($escrowId, $userId, $reason) {
            $escrow = $this->getEscrow($escrowId);

            // Authorization check: buyer or seller can dispute
            $this->verifyUserCanDispute($escrow, $userId);

            $currentStatus = EscrowStatus::from($escrow->status);
            if (!$currentStatus->canDispute()) {
                throw new \Exception("Cannot dispute from status: {$escrow->status}");
            }

            $this->updateStatus($escrowId, EscrowStatus::DISPUTED, 'DISPUTE_CREATED', $userId, [
                'reason' => $reason,
            ]);

            return [
                'escrow_id' => $escrowId,
                'status' => EscrowStatus::DISPUTED->value,
                'message' => 'Dispute created',
            ];
        });
    }

    // Authorization helper methods
    private function verifyUserIsSeller(object $escrow, string $userId): void
    {
        if ($escrow->seller_id !== $userId) {
            throw new \Exception('Only seller can perform this action');
        }
    }

    private function verifyUserCanRelease(object $escrow, string $userId): void
    {
        if ($escrow->buyer_id !== $userId && $escrow->seller_id !== $userId && !$this->isAdmin($userId)) {
            throw new \Exception('Not authorized to release funds');
        }
    }

    private function verifyUserCanRefund(object $escrow, string $userId): void
    {
        if ($escrow->buyer_id !== $userId && $escrow->seller_id !== $userId && !$this->isAdmin($userId)) {
            throw new \Exception('Not authorized to refund');
        }
    }

    private function verifyUserCanDispute(object $escrow, string $userId): void
    {
        if ($escrow->buyer_id !== $userId && $escrow->seller_id !== $userId) {
            throw new \Exception('Only buyer or seller can create dispute');
        }
    }

    // Helper methods
    private function getEscrow(string $escrowId): object
    {
        $escrow = DB::table('escrows')->where('id', $escrowId)->first();
        if (!$escrow) {
            throw new \Exception('Escrow not found');
        }
        return $escrow;
    }

    private function updateStatus(
        string $escrowId,
        EscrowStatus $status,
        string $eventType,
        ?string $actorId,
        array $metadata = []
    ): void {
        DB::table('escrows')->where('id', $escrowId)->update([
            'status' => $status->value,
            'updated_at' => now(),
        ]);

        $this->addTimelineEntry($escrowId, $eventType, $actorId, $metadata);
    }

    /**
     * Add timeline entry with correct structure.
     * 
     * Timeline uses: event, actor_id, metadata
     * NOT: status, note
     */
    private function addTimelineEntry(
        string $escrowId,
        string $eventType,
        ?string $actorId,
        array $metadata = []
    ): void {
        try {
            DB::table('escrow_timelines')->insert([
                'id' => Str::uuid()->toString(),
                'escrow_id' => $escrowId,
                'event' => $eventType,        // Fixed: was 'status'
                'actor_id' => $actorId,       // Fixed: was missing
                'metadata' => !empty($metadata) ? json_encode($metadata) : null,
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to add timeline entry', [
                'escrow_id' => $escrowId,
                'event' => $eventType,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - timeline is not critical
        }
    }

    private function createFinancialMessage(string $tenantId, ProcessingCode $code, int $amount): string
    {
        $messageId = Str::uuid()->toString();
        try {
            DB::table('financial_messages')->insert([
                'id' => $messageId,
                'tenant_id' => $tenantId,
                'stan' => $this->generateStan((int) $tenantId),
                'rrn' => $this->generateRrn(),
                'processing_code' => $code->value,
                'amount' => $amount,
                'phase' => MessagePhase::INITIATED->value,
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create financial message', [
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Failed to create financial transaction');
        }
        return $messageId;
    }

    private function getWalletAccount(string $userId): string
    {
        $wallet = DB::table('wallets')->where('user_id', $userId)->first();
        if (!$wallet) {
            throw new \Exception('User wallet not found');
        }
        return $wallet->account_id;
    }

    private function getPlatformWallet(): string
    {
        $wallet = DB::table('platform_wallets')->where('type', 'revenue')->first();
        if (!$wallet) {
            throw new \Exception('Platform wallet not found');
        }
        return $wallet->account_id;
    }

    private function isAdmin(string $userId): bool
    {
        return DB::table('admins')
            ->where('id', $userId)
            ->where('is_active', true)
            ->exists();
    }

    private function generateStan(int $tenantId): string
    {
        return STAN::generate($tenantId)->value();
    }

    private function generateRrn(): string
    {
        return RRN::generate()->value();
    }
}
