<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Models\Escrow;
use App\Models\EscrowTimeline;
use App\Models\Wallet;
use App\Domain\Escrow\Enums\EscrowStatus;
use App\Domain\Escrow\Enums\EscrowEvent;
use App\Domain\Ledger\Contracts\LedgerServiceInterface;
use App\Exceptions\Escrow\InvalidStateTransitionException;
use App\Exceptions\Escrow\InsufficientBalanceException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class EscrowService
{
    public function __construct(
        private LedgerServiceInterface $ledgerService
    ) {}

    /**
     * Create escrow transaction
     */
    public function create(
        int $tenantId,
        int $buyerId,
        int $sellerId,
        int $amount,
        string $title,
        string $description,
        string $idempotencyKey
    ): Escrow {
        return DB::transaction(function () use (
            $tenantId,
            $buyerId,
            $sellerId,
            $amount,
            $title,
            $description,
            $idempotencyKey
        ) {
            // Get wallets
            $buyerWallet = Wallet::where('tenant_id', $tenantId)
                ->where('user_id', $buyerId)
                ->firstOrFail();
            $sellerWallet = Wallet::where('tenant_id', $tenantId)
                ->where('user_id', $sellerId)
                ->firstOrFail();

            // Create escrow
            $escrow = Escrow::create([
                'tenant_id' => $tenantId,
                'buyer_id' => $buyerId,
                'seller_id' => $sellerId,
                'buyer_wallet_id' => $buyerWallet->id,
                'seller_wallet_id' => $sellerWallet->id,
                'amount' => $amount,
                'title' => $title,
                'description' => $description,
                'idempotency_key' => $idempotencyKey,
            ]);

            // Record timeline event
            $this->recordEvent(
                $escrow,
                EscrowEvent::CREATED,
                'User',
                $buyerId,
                ['amount' => $amount]
            );

            Log::info('Escrow created', [
                'escrow_id' => $escrow->id,
                'buyer_id' => $buyerId,
                'seller_id' => $sellerId,
                'amount' => $amount,
            ]);

            return $escrow;
        });
    }

    /**
     * Fund escrow (AUTH phase - lock funds)
     */
    public function fund(
        string $escrowId,
        int $userId,
        string $idempotencyKey
    ): Escrow {
        return DB::transaction(function () use ($escrowId, $userId, $idempotencyKey) {
            $escrow = Escrow::lockForUpdate()->findOrFail($escrowId);

            // Validate state transition
            if (!$escrow->canTransitionTo(EscrowStatus::FUNDED)) {
                throw new InvalidStateTransitionException(
                    "Cannot fund escrow in {$escrow->status->value} status"
                );
            }

            // Validate buyer
            if ($escrow->buyer_id !== $userId) {
                throw new \InvalidArgumentException('Only buyer can fund escrow');
            }

            // Check balance
            $wallet = $escrow->buyerWallet()->lockForUpdate()->first();
            if (!$wallet->hasAvailableBalance($escrow->getAmount())) {
                throw new InsufficientBalanceException();
            }

            // Lock funds via ledger (AUTH phase)
            $authBatchId = $this->ledgerService->lockFunds(
                tenantId: $escrow->tenant_id,
                walletId: $wallet->id,
                amount: $escrow->amount,
                escrowId: $escrow->id,
                idempotencyKey: $idempotencyKey
            );

            // Update escrow status
            $escrow->update([
                'status' => EscrowStatus::FUNDED,
                'funded_at' => now(),
                'auth_posting_batch_id' => $authBatchId,
            ]);

            // Record event
            $this->recordEvent(
                $escrow,
                EscrowEvent::FUNDED,
                'User',
                $userId,
                ['auth_batch_id' => $authBatchId]
            );

            Log::info('Escrow funded', [
                'escrow_id' => $escrow->id,
                'amount' => $escrow->amount,
                'auth_batch_id' => $authBatchId,
            ]);

            return $escrow->fresh();
        });
    }

    /**
     * Mark as delivered (buyer confirmation)
     */
    public function markDelivered(
        string $escrowId,
        int $userId
    ): Escrow {
        return DB::transaction(function () use ($escrowId, $userId) {
            $escrow = Escrow::lockForUpdate()->findOrFail($escrowId);

            if (!$escrow->canTransitionTo(EscrowStatus::DELIVERED)) {
                throw new InvalidStateTransitionException(
                    "Cannot mark as delivered in {$escrow->status->value} status"
                );
            }

            // Only buyer or seller can mark delivered
            if (!in_array($userId, [$escrow->buyer_id, $escrow->seller_id])) {
                throw new \InvalidArgumentException('Unauthorized');
            }

            $escrow->update([
                'status' => EscrowStatus::DELIVERED,
                'delivered_at' => now(),
            ]);

            $this->recordEvent(
                $escrow,
                EscrowEvent::DELIVERED,
                'User',
                $userId
            );

            Log::info('Escrow marked as delivered', ['escrow_id' => $escrow->id]);

            return $escrow->fresh();
        });
    }

    /**
     * Release funds to seller (PRESENTMENT phase)
     */
    public function release(
        string $escrowId,
        int $userId,
        string $idempotencyKey,
        bool $isAutoRelease = false
    ): Escrow {
        return DB::transaction(function () use ($escrowId, $userId, $idempotencyKey, $isAutoRelease) {
            $escrow = Escrow::lockForUpdate()->findOrFail($escrowId);

            if (!$escrow->canTransitionTo(EscrowStatus::RELEASED)) {
                throw new InvalidStateTransitionException(
                    "Cannot release escrow in {$escrow->status->value} status"
                );
            }

            // Release funds to seller (PRESENTMENT phase)
            $settlementBatchId = $this->ledgerService->releaseFunds(
                tenantId: $escrow->tenant_id,
                sourceWalletId: $escrow->buyer_wallet_id,
                destinationWalletId: $escrow->seller_wallet_id,
                amount: $escrow->amount,
                feeAmount: $escrow->fee_amount,
                escrowId: $escrow->id,
                idempotencyKey: $idempotencyKey,
                authBatchId: $escrow->auth_posting_batch_id
            );

            $escrow->update([
                'status' => EscrowStatus::RELEASED,
                'released_at' => now(),
                'settlement_posting_batch_id' => $settlementBatchId,
            ]);

            $event = $isAutoRelease ? EscrowEvent::AUTO_RELEASED : EscrowEvent::RELEASED;
            $this->recordEvent(
                $escrow,
                $event,
                $isAutoRelease ? 'System' : 'User',
                $userId,
                ['settlement_batch_id' => $settlementBatchId]
            );

            Log::info('Escrow released', [
                'escrow_id' => $escrow->id,
                'is_auto' => $isAutoRelease,
                'settlement_batch_id' => $settlementBatchId,
            ]);

            return $escrow->fresh();
        });
    }

    /**
     * Refund to buyer (REVERSAL phase)
     */
    public function refund(
        string $escrowId,
        int $userId,
        string $idempotencyKey,
        bool $isAutoRefund = false
    ): Escrow {
        return DB::transaction(function () use ($escrowId, $userId, $idempotencyKey, $isAutoRefund) {
            $escrow = Escrow::lockForUpdate()->findOrFail($escrowId);

            if (!$escrow->canTransitionTo(EscrowStatus::REFUNDED)) {
                throw new InvalidStateTransitionException(
                    "Cannot refund escrow in {$escrow->status->value} status"
                );
            }

            // Refund to buyer (REVERSAL phase)
            $reversalBatchId = $this->ledgerService->refundFunds(
                tenantId: $escrow->tenant_id,
                walletId: $escrow->buyer_wallet_id,
                amount: $escrow->amount,
                escrowId: $escrow->id,
                idempotencyKey: $idempotencyKey,
                authBatchId: $escrow->auth_posting_batch_id
            );

            $escrow->update([
                'status' => EscrowStatus::REFUNDED,
                'refunded_at' => now(),
                'settlement_posting_batch_id' => $reversalBatchId,
            ]);

            $event = $isAutoRefund ? EscrowEvent::AUTO_REFUNDED : EscrowEvent::REFUNDED;
            $this->recordEvent(
                $escrow,
                $event,
                $isAutoRefund ? 'System' : 'User',
                $userId,
                ['reversal_batch_id' => $reversalBatchId]
            );

            Log::info('Escrow refunded', [
                'escrow_id' => $escrow->id,
                'is_auto' => $isAutoRefund,
                'reversal_batch_id' => $reversalBatchId,
            ]);

            return $escrow->fresh();
        });
    }

    /**
     * Open dispute
     */
    public function dispute(
        string $escrowId,
        int $userId,
        string $reason
    ): Escrow {
        return DB::transaction(function () use ($escrowId, $userId, $reason) {
            $escrow = Escrow::lockForUpdate()->findOrFail($escrowId);

            if (!$escrow->canTransitionTo(EscrowStatus::DISPUTED)) {
                throw new InvalidStateTransitionException(
                    "Cannot dispute escrow in {$escrow->status->value} status"
                );
            }

            $escrow->update([
                'status' => EscrowStatus::DISPUTED,
                'disputed_at' => now(),
            ]);

            $this->recordEvent(
                $escrow,
                EscrowEvent::DISPUTED,
                'User',
                $userId,
                ['reason' => $reason]
            );

            Log::warning('Escrow disputed', [
                'escrow_id' => $escrow->id,
                'user_id' => $userId,
                'reason' => $reason,
            ]);

            return $escrow->fresh();
        });
    }

    /**
     * Record timeline event
     */
    private function recordEvent(
        Escrow $escrow,
        EscrowEvent $event,
        string $actorType,
        ?int $actorId,
        array $metadata = []
    ): void {
        EscrowTimeline::create([
            'escrow_id' => $escrow->id,
            'event' => $event,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'metadata' => $metadata,
        ]);
    }
}
