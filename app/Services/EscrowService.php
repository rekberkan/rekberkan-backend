<?php

namespace App\Services;

use App\Models\Escrow;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EscrowService
{
    /**
     * Create escrow with tenant enforcement
     */
    public function create(User $buyer, array $data, int $tenantId): Escrow
    {
        return DB::transaction(function () use ($buyer, $data, $tenantId) {
            // Validate seller belongs to same tenant
            $seller = User::where('id', $data['seller_id'])
                ->where('tenant_id', $tenantId)
                ->firstOrFail();

            // Validate buyer wallet
            $wallet = $buyer->wallet;
            if (!$wallet || $wallet->balance < $data['amount']) {
                throw new \RuntimeException('Insufficient balance');
            }

            // Create escrow
            $escrow = Escrow::create([
                'tenant_id' => $tenantId,
                'buyer_id' => $buyer->id,
                'seller_id' => $seller->id,
                'amount' => $data['amount'],
                'description' => $data['description'],
                'status' => 'pending',
                'expires_at' => now()->addDays($data['duration_days'] ?? 7),
            ]);

            // Lock funds in buyer wallet
            $wallet->decrement('balance', $data['amount']);
            $wallet->increment('locked_balance', $data['amount']);

            return $escrow;
        });
    }

    /**
     * Find escrow by ID with tenant filter (prevents cross-tenant access)
     */
    public function findByIdWithTenant(string $id, int $tenantId): ?Escrow
    {
        return Escrow::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    /**
     * Release escrow funds to seller
     */
    public function release(Escrow $escrow): void
    {
        if ($escrow->status !== 'pending') {
            throw new \RuntimeException('Escrow cannot be released in current state');
        }

        DB::transaction(function () use ($escrow) {
            $buyer = $escrow->buyer;
            $seller = $escrow->seller;

            // Release funds from buyer's locked balance
            $buyer->wallet->decrement('locked_balance', $escrow->amount);

            // Add to seller's balance
            $seller->wallet->increment('balance', $escrow->amount);

            // Update escrow status
            $escrow->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        });
    }

    /**
     * Cancel escrow and refund buyer
     */
    public function cancel(Escrow $escrow, User $requestedBy): void
    {
        if ($escrow->status !== 'pending') {
            throw new \RuntimeException('Escrow cannot be cancelled in current state');
        }

        DB::transaction(function () use ($escrow) {
            $buyer = $escrow->buyer;

            // Refund to buyer
            $buyer->wallet->increment('balance', $escrow->amount);
            $buyer->wallet->decrement('locked_balance', $escrow->amount);

            // Update escrow status
            $escrow->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);
        });
    }
}
