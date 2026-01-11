<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Models\Voucher;
use App\Models\UserVoucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Carbon\Carbon;

final class VoucherService
{
    /**
     * Get available vouchers for user
     */
    public function getAvailableVouchers(int $userId): Collection
    {
        return Voucher::where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->where(function ($query) {
                $query->whereNull('usage_limit')
                    ->orWhereRaw('usage_count < usage_limit');
            })
            ->orderBy('discount_amount', 'desc')
            ->get()
            ->filter(function ($voucher) use ($userId) {
                // Check if user already used this voucher
                if ($voucher->max_uses_per_user) {
                    $userUsageCount = UserVoucher::where('voucher_id', $voucher->id)
                        ->where('user_id', $userId)
                        ->where('status', 'used')
                        ->count();
                        
                    return $userUsageCount < $voucher->max_uses_per_user;
                }
                
                return true;
            });
    }

    /**
     * Get user's vouchers
     */
    public function getUserVouchers(int $userId): Collection
    {
        return UserVoucher::with('voucher')
            ->where('user_id', $userId)
            ->where('status', '!=', 'expired')
            ->whereHas('voucher', function ($query) {
                $query->where('status', 'active');
            })
            ->orderBy('expires_at', 'asc')
            ->get();
    }

    /**
     * Validate voucher code and amount
     */
    public function validateVoucher(string $code, int $userId, float $amount): bool
    {
        try {
            $result = $this->checkVoucherValidity($code, $userId, $amount);
            return $result['valid'];
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Apply voucher to transaction
     */
    public function applyVoucher(string $code, int $userId, float $amount, ?string $transactionId = null): array
    {
        return DB::transaction(function () use ($code, $userId, $amount, $transactionId) {
            // Validate voucher
            $validationResult = $this->checkVoucherValidity($code, $userId, $amount);
            
            if (!$validationResult['valid']) {
                throw new \Exception($validationResult['reason']);
            }
            
            $voucher = $validationResult['voucher'];
            
            // Calculate discount
            $discount = $this->calculateDiscount($voucher, $amount);
            
            // Create or update user voucher record
            $userVoucher = UserVoucher::firstOrCreate(
                [
                    'user_id' => $userId,
                    'voucher_id' => $voucher->id,
                    'status' => 'available',
                ],
                [
                    'assigned_at' => now(),
                    'expires_at' => $voucher->ends_at,
                ]
            );
            
            // Mark voucher as used
            $userVoucher->update([
                'status' => 'used',
                'used_at' => now(),
                'transaction_id' => $transactionId,
                'discount_amount' => $discount,
            ]);
            
            // Increment voucher usage count
            $voucher->increment('usage_count');
            
            // Calculate final amount
            $finalAmount = max(0, $amount - $discount);
            
            return [
                'voucher' => $voucher,
                'original_amount' => $amount,
                'discount' => $discount,
                'final_amount' => $finalAmount,
                'user_voucher_id' => $userVoucher->id,
            ];
        });
    }

    /**
     * Check voucher validity
     */
    private function checkVoucherValidity(string $code, int $userId, float $amount): array
    {
        $voucher = Voucher::where('code', $code)->first();
        
        if (!$voucher) {
            return [
                'valid' => false,
                'reason' => 'Voucher not found',
            ];
        }
        
        if ($voucher->status !== 'active') {
            return [
                'valid' => false,
                'reason' => 'Voucher is not active',
            ];
        }
        
        // Check validity period
        if (Carbon::parse($voucher->starts_at)->isFuture()) {
            return [
                'valid' => false,
                'reason' => 'Voucher not yet valid',
            ];
        }
        
        if (Carbon::parse($voucher->ends_at)->isPast()) {
            return [
                'valid' => false,
                'reason' => 'Voucher has expired',
            ];
        }
        
        // Check usage limit
        if ($voucher->usage_limit && $voucher->usage_count >= $voucher->usage_limit) {
            return [
                'valid' => false,
                'reason' => 'Voucher usage limit reached',
            ];
        }
        
        // Check user usage limit
        if ($voucher->max_uses_per_user) {
            $userUsageCount = UserVoucher::where('voucher_id', $voucher->id)
                ->where('user_id', $userId)
                ->where('status', 'used')
                ->count();
                
            if ($userUsageCount >= $voucher->max_uses_per_user) {
                return [
                    'valid' => false,
                    'reason' => 'User has reached maximum uses for this voucher',
                ];
            }
        }
        
        // Check minimum transaction amount
        if ($voucher->min_transaction_amount && $amount < $voucher->min_transaction_amount) {
            return [
                'valid' => false,
                'reason' => "Minimum transaction amount is {$voucher->min_transaction_amount}",
            ];
        }
        
        return [
            'valid' => true,
            'voucher' => $voucher,
            'reason' => null,
        ];
    }

    /**
     * Calculate discount amount
     */
    private function calculateDiscount(Voucher $voucher, float $amount): float
    {
        if ($voucher->discount_type === 'percentage') {
            $discount = ($amount * $voucher->discount_value) / 100;
            
            // Apply max discount cap if set
            if ($voucher->max_discount_amount) {
                $discount = min($discount, $voucher->max_discount_amount);
            }
        } else {
            // Fixed amount discount
            $discount = $voucher->discount_value;
        }
        
        // Discount cannot exceed transaction amount
        return min($discount, $amount);
    }

    /**
     * Expire unused vouchers
     */
    public function expireOldVouchers(): int
    {
        return UserVoucher::where('status', 'available')
            ->where('expires_at', '<', now())
            ->update([
                'status' => 'expired',
                'expired_at' => now(),
            ]);
    }
}
