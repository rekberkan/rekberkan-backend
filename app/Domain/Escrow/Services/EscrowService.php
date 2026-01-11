<?php

declare(strict_types=1);

namespace App\Domain\Escrow\Services;

use App\Domain\Escrow\Enums\EscrowStatus;
use App\Domain\Financial\Enums\ProcessingCode;
use App\Domain\Financial\Enums\ReasonCode;
use App\Domain\Financial\Services\PostingService;
use App\Domain\Financial\ValueObjects\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EscrowService
{
    public function __construct(
        private PostingService $postingService
    ) {}

    /**
     * Create escrow transaction.
     */
    public function create(
        string $tenantId,
        string $senderId,
        string $recipientId,
        Money $amount,
        string $description,
        ?int $expiresInDays = 30
    ): array {
        return DB::transaction(function () use ($tenantId, $senderId, $recipientId, $amount, $description, $expiresInDays) {
            $escrowId = Str::uuid()->toString();
            
            // Calculate fees (2% platform fee)
            $platformFee = $amount->multiply('0.02');
            $totalAmount = $amount->add($platformFee);

            // Create escrow record
            DB::table('escrows')->insert([
                'id' => $escrowId,
                'tenant_id' => $tenantId,
                'sender_id' => $senderId,
                'recipient_id' => $recipientId,
                'amount' => $amount->getMinorUnits(),
                'platform_fee' => $platformFee->getMinorUnits(),
                'total_amount' => $totalAmount->getMinorUnits(),
                'description' => $description,
                'status' => EscrowStatus::PENDING_PAYMENT->value,
                'expires_at' => now()->addDays($expiresInDays),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Create timeline entry
            $this->addTimelineEntry($escrowId, EscrowStatus::PENDING_PAYMENT, 'Escrow created');

            return [
                'escrow_id' => $escrowId,
                'status' => EscrowStatus::PENDING_PAYMENT->value,
                'amount' => $amount,
                'platform_fee' => $platformFee,
                'total_amount' => $totalAmount,
            ];
        });
    }

    /**
     * Fund escrow (lock funds from sender).
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

            // Create financial message
            $messageId = $this->createFinancialMessage(
                $escrow->tenant_id,
                ProcessingCode::ESCROW_LOCK,
                $escrow->total_amount
            );

            // Get sender and platform wallet accounts
            $senderWallet = $this->getWalletAccount($escrow->sender_id);
            $platformWallet = $this->getPlatformWallet();

            // Post ledger entries (debit sender, credit escrow + platform fee)
            $this->postingService->post(
                messageId: $messageId,
                tenantId: $escrow->tenant_id,
                processingCode: ProcessingCode::ESCROW_LOCK,
                entries: [
                    [
                        'account_id' => $senderWallet,
                        'type' => 'debit',
                        'amount' => $escrow->total_amount,
                        'description' => "Escrow lock: {$escrow->description}",
                    ],
                    [
                        'account_id' => $escrowId, // Escrow account
                        'type' => 'credit',
                        'amount' => $escrow->amount,
                        'description' => "Escrow funds: {$escrow->description}",
                    ],
                    [
                        'account_id' => $platformWallet,
                        'type' => 'credit',
                        'amount' => $escrow->platform_fee,
                        'description' => 'Platform fee',
                    ],
                ],
                idempotencyKey: $idempotencyKey
            );

            // Update escrow status
            $this->updateStatus($escrowId, EscrowStatus::FUNDED, 'Funds locked in escrow');

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

            // Only recipient can mark in progress
            if ($escrow->recipient_id !== $userId) {
                throw new \Exception('Only recipient can mark escrow in progress');
            }

            $currentStatus = EscrowStatus::from($escrow->status);
            if (!$currentStatus->canTransitionTo(EscrowStatus::IN_PROGRESS)) {
                throw new \Exception("Cannot mark in progress from status: {$escrow->status}");
            }

            $this->updateStatus($escrowId, EscrowStatus::IN_PROGRESS, 'Work in progress');

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

            if ($escrow->recipient_id !== $userId) {
                throw new \Exception('Only recipient can mark as delivered');
            }

            $currentStatus = EscrowStatus::from($escrow->status);
            if (!$currentStatus->canTransitionTo(EscrowStatus::DELIVERED)) {
                throw new \Exception("Cannot mark delivered from status: {$escrow->status}");
            }

            $this->updateStatus($escrowId, EscrowStatus::DELIVERED, 'Goods/service delivered', [
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
     * Release funds to recipient.
     */
    public function release(string $escrowId, string $userId, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($escrowId, $userId, $idempotencyKey) {
            $escrow = $this->getEscrow($escrowId);

            $currentStatus = EscrowStatus::from($escrow->status);
            if (!$currentStatus->canRelease()) {
                throw new \Exception("Cannot release from status: {$escrow->status}");
            }

            // Only sender or admin can release
            if ($escrow->sender_id !== $userId && !$this->isAdmin($userId)) {
                throw new \Exception('Only sender or admin can release funds');
            }

            // Create financial message
            $messageId = $this->createFinancialMessage(
                $escrow->tenant_id,
                ProcessingCode::ESCROW_RELEASE,
                $escrow->amount
            );

            // Get recipient wallet
            $recipientWallet = $this->getWalletAccount($escrow->recipient_id);

            // Post ledger entries (debit escrow, credit recipient)
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
                        'account_id' => $recipientWallet,
                        'type' => 'credit',
                        'amount' => $escrow->amount,
                        'description' => 'Payment received',
                    ],
                ],
                idempotencyKey: $idempotencyKey
            );

            $this->updateStatus($escrowId, EscrowStatus::COMPLETED, 'Funds released to recipient');

            return [
                'escrow_id' => $escrowId,
                'status' => EscrowStatus::COMPLETED->value,
                'message' => 'Funds released successfully',
            ];
        });
    }

    /**
     * Refund to sender.
     */
    public function refund(string $escrowId, string $userId, string $reason, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($escrowId, $userId, $reason, $idempotencyKey) {
            $escrow = $this->getEscrow($escrowId);

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

            $senderWallet = $this->getWalletAccount($escrow->sender_id);

            // Post ledger entries (debit escrow, credit sender)
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
                        'account_id' => $senderWallet,
                        'type' => 'credit',
                        'amount' => $escrow->amount,
                        'description' => 'Refund received',
                    ],
                ],
                idempotencyKey: $idempotencyKey
            );

            $this->updateStatus($escrowId, EscrowStatus::REFUNDED, $reason);

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

            $currentStatus = EscrowStatus::from($escrow->status);
            if (!$currentStatus->canDispute()) {
                throw new \Exception("Cannot dispute from status: {$escrow->status}");
            }

            // Only sender can dispute
            if ($escrow->sender_id !== $userId) {
                throw new \Exception('Only sender can create dispute');
            }

            $this->updateStatus($escrowId, EscrowStatus::DISPUTED, $reason, [
                'disputed_by' => $userId,
            ]);

            return [
                'escrow_id' => $escrowId,
                'status' => EscrowStatus::DISPUTED->value,
                'message' => 'Dispute created',
            ];
        });
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

    private function updateStatus(string $escrowId, EscrowStatus $status, string $note, array $metadata = []): void
    {
        DB::table('escrows')->where('id', $escrowId)->update([
            'status' => $status->value,
            'updated_at' => now(),
        ]);

        $this->addTimelineEntry($escrowId, $status, $note, $metadata);
    }

    private function addTimelineEntry(string $escrowId, EscrowStatus $status, string $note, array $metadata = []): void
    {
        DB::table('escrow_timelines')->insert([
            'id' => Str::uuid()->toString(),
            'escrow_id' => $escrowId,
            'status' => $status->value,
            'note' => $note,
            'metadata' => !empty($metadata) ? json_encode($metadata) : null,
            'created_at' => now(),
        ]);
    }

    private function createFinancialMessage(string $tenantId, ProcessingCode $code, int $amount): string
    {
        $messageId = Str::uuid()->toString();
        DB::table('financial_messages')->insert([
            'id' => $messageId,
            'tenant_id' => $tenantId,
            'stan' => $this->generateStan(),
            'rrn' => $this->generateRrn(),
            'processing_code' => $code->value,
            'amount' => $amount,
            'phase' => 'INITIATED',
            'created_at' => now(),
        ]);
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
        return DB::table('admins')->where('id', $userId)->exists();
    }

    private function generateStan(): string
    {
        return str_pad((string) rand(1, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function generateRrn(): string
    {
        return date('ymdHis') . str_pad((string) rand(1, 999), 3, '0', STR_PAD_LEFT);
    }
}
