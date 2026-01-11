<?php

namespace App\Services;

use App\Models\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * NEW SERVICE: Membership management service.
 */
class MembershipService
{
    /**
     * Get user's current membership.
     */
    public function getUserMembership(int $userId): ?Membership
    {
        return Membership::where('user_id', $userId)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Create or upgrade membership.
     */
    public function subscribe(
        int $userId,
        int $tenantId,
        string $tier,
        string $paymentMethod = 'wallet'
    ): Membership {
        try {
            DB::beginTransaction();

            // Validate tier
            if (!in_array($tier, ['free', 'bronze', 'silver', 'gold', 'platinum'])) {
                throw new \Exception('Invalid membership tier');
            }

            // Check if user already has active membership
            $existing = $this->getUserMembership($userId);

            if ($existing) {
                // Upgrade/downgrade logic
                $this->changeTier($existing, $tier);
                DB::commit();
                return $existing->fresh();
            }

            // Create new membership
            $config = Membership::getTierConfig($tier);
            $price = $config['price'];

            // Deduct payment (if not free)
            if ($price > 0) {
                $this->processPayment($userId, $price, $paymentMethod);
            }

            $membership = Membership::create([
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'tier' => $tier,
                'status' => 'active',
                'started_at' => now(),
                'expires_at' => now()->addMonth(),
                'auto_renew' => true,
                'payment_method' => $paymentMethod,
            ]);

            DB::commit();

            Log::info('Membership created', [
                'user_id' => $userId,
                'tier' => $tier,
                'price' => $price,
            ]);

            return $membership;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Change membership tier.
     */
    private function changeTier(Membership $membership, string $newTier): void
    {
        $oldConfig = Membership::getTierConfig($membership->tier);
        $newConfig = Membership::getTierConfig($newTier);

        $priceDiff = $newConfig['price'] - $oldConfig['price'];

        if ($priceDiff > 0) {
            // Upgrade: charge difference (prorated)
            $daysRemaining = now()->diffInDays($membership->expires_at);
            $proratedAmount = ($priceDiff / 30) * $daysRemaining;
            
            $this->processPayment(
                $membership->user_id,
                (int) $proratedAmount,
                $membership->payment_method
            );
        }

        $membership->update([
            'tier' => $newTier,
            'updated_at' => now(),
        ]);

        Log::info('Membership tier changed', [
            'user_id' => $membership->user_id,
            'old_tier' => $membership->tier,
            'new_tier' => $newTier,
        ]);
    }

    /**
     * Cancel membership.
     */
    public function cancel(int $userId): bool
    {
        $membership = $this->getUserMembership($userId);

        if (!$membership) {
            throw new \Exception('No active membership found');
        }

        $membership->update([
            'auto_renew' => false,
            'status' => 'cancelled',
        ]);

        Log::info('Membership cancelled', ['user_id' => $userId]);

        return true;
    }

    /**
     * Renew membership (auto or manual).
     */
    public function renew(Membership $membership): bool
    {
        try {
            DB::beginTransaction();

            $config = Membership::getTierConfig($membership->tier);
            $price = $config['price'];

            if ($price > 0) {
                $this->processPayment(
                    $membership->user_id,
                    $price,
                    $membership->payment_method
                );
            }

            $membership->update([
                'expires_at' => $membership->expires_at->addMonth(),
                'status' => 'active',
            ]);

            DB::commit();

            Log::info('Membership renewed', [
                'user_id' => $membership->user_id,
                'tier' => $membership->tier,
            ]);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Process payment for membership.
     */
    private function processPayment(
        int $userId,
        int $amount,
        string $paymentMethod
    ): void {
        // TODO: Integrate with WalletService or PaymentService
        // For now, just log
        Log::info('Membership payment processed', [
            'user_id' => $userId,
            'amount' => $amount,
            'method' => $paymentMethod,
        ]);
    }

    /**
     * Get all available tiers.
     */
    public function getAvailableTiers(): array
    {
        $tiers = ['free', 'bronze', 'silver', 'gold', 'platinum'];
        $result = [];

        foreach ($tiers as $tier) {
            $result[] = array_merge(
                ['tier' => $tier],
                Membership::getTierConfig($tier)
            );
        }

        return $result;
    }
}
