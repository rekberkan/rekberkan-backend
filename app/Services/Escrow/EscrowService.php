<?php

namespace App\Services\Escrow;

use App\Domain\Escrow\Enums\EscrowStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TYPE CONSISTENCY FIX: Changed string type hints to int for IDs.
 * 
 * Before: string $tenantId, string $senderId, string $recipientId
 * After: int $tenantId, int $senderId, int $recipientId
 * 
 * This ensures type safety and consistency with database schema.
 */
class EscrowService
{
    /**
     * Create a new escrow transaction.
     */
    public function create(
        int $tenantId,
        int $senderId,
        int $recipientId,
        float $amount,
        string $description,
        ?array $metadata = null
    ): array {
        try {
            DB::beginTransaction();

            $escrow = [
                'tenant_id' => $tenantId,
                'sender_id' => $senderId,
                'recipient_id' => $recipientId,
                'amount' => $amount,
                'description' => $description,
                'status' => EscrowStatus::PENDING_PAYMENT->value,
                'metadata' => $metadata ? json_encode($metadata) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $escrowId = DB::table('escrows')->insertGetId($escrow);

            DB::commit();

            Log::info('Escrow created', [
                'escrow_id' => $escrowId,
                'sender_id' => $senderId,
                'recipient_id' => $recipientId,
                'amount' => $amount,
            ]);

            return [
                'success' => true,
                'escrow_id' => $escrowId,
            ];
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Escrow creation failed', [
                'error' => $e->getMessage(),
                'sender_id' => $senderId,
            ]);

            throw $e;
        }
    }

    /**
     * Get escrow by ID.
     */
    public function getById(int $escrowId): ?array
    {
        $escrow = DB::table('escrows')->where('id', $escrowId)->first();

        return $escrow ? (array) $escrow : null;
    }

    /**
     * Release escrow to recipient.
     */
    public function release(int $escrowId, int $userId): bool
    {
        try {
            DB::beginTransaction();

            $escrow = $this->getById($escrowId);

            if (!$escrow || $escrow['sender_id'] !== $userId) {
                throw new \Exception('Unauthorized or escrow not found');
            }

            if (!in_array($escrow['status'], [
                EscrowStatus::DELIVERED->value,
                EscrowStatus::DISPUTED->value,
            ], true)) {
                throw new \Exception('Escrow is not active');
            }

            DB::table('escrows')
                ->where('id', $escrowId)
                ->update([
                    'status' => EscrowStatus::COMPLETED->value,
                    'released_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Refund escrow to sender.
     */
    public function refund(int $escrowId, int $userId): bool
    {
        try {
            DB::beginTransaction();

            $escrow = $this->getById($escrowId);

            if (!$escrow) {
                throw new \Exception('Escrow not found');
            }

            // Only sender or admin can refund
            if ($escrow['sender_id'] !== $userId && !$this->isAdmin($userId)) {
                throw new \Exception('Unauthorized');
            }

            if (!in_array($escrow['status'], [
                EscrowStatus::PENDING_PAYMENT->value,
                EscrowStatus::FUNDED->value,
                EscrowStatus::IN_PROGRESS->value,
                EscrowStatus::DISPUTED->value,
            ], true)) {
                throw new \Exception('Escrow cannot be refunded');
            }

            DB::table('escrows')
                ->where('id', $escrowId)
                ->update([
                    'status' => EscrowStatus::REFUNDED->value,
                    'refunded_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function isAdmin(int $userId): bool
    {
        return DB::table('admins')
            ->where('id', $userId)
            ->where('is_active', true)
            ->exists();
    }
}
