<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NEW MODEL: Membership system untuk tiered benefits.
 * 
 * Tiers:
 * - free: Default tier (0/month)
 * - bronze: Rp 50.000/month
 * - silver: Rp 150.000/month  
 * - gold: Rp 300.000/month
 * - platinum: Rp 500.000/month
 */
class Membership extends Model
{
    protected $fillable = [
        'user_id',
        'tenant_id',
        'tier',
        'status',
        'started_at',
        'expires_at',
        'auto_renew',
        'payment_method',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'auto_renew' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * Membership tiers configuration.
     */
    public static function getTierConfig(string $tier): array
    {
        $configs = [
            'free' => [
                'name' => 'Free',
                'price' => 0,
                'benefits' => [
                    'escrow_fee_discount' => 0,
                    'withdrawal_fee_discount' => 0,
                    'max_daily_transactions' => 5,
                    'max_transaction_amount' => 5000000,
                    'priority_support' => false,
                    'dispute_mediation' => false,
                ],
            ],
            'bronze' => [
                'name' => 'Bronze',
                'price' => 50000,
                'benefits' => [
                    'escrow_fee_discount' => 10, // 10% discount
                    'withdrawal_fee_discount' => 0,
                    'max_daily_transactions' => 10,
                    'max_transaction_amount' => 10000000,
                    'priority_support' => false,
                    'dispute_mediation' => false,
                ],
            ],
            'silver' => [
                'name' => 'Silver',
                'price' => 150000,
                'benefits' => [
                    'escrow_fee_discount' => 20,
                    'withdrawal_fee_discount' => 50,
                    'max_daily_transactions' => 20,
                    'max_transaction_amount' => 25000000,
                    'priority_support' => true,
                    'dispute_mediation' => false,
                ],
            ],
            'gold' => [
                'name' => 'Gold',
                'price' => 300000,
                'benefits' => [
                    'escrow_fee_discount' => 30,
                    'withdrawal_fee_discount' => 100, // Free withdrawals
                    'max_daily_transactions' => 50,
                    'max_transaction_amount' => 50000000,
                    'priority_support' => true,
                    'dispute_mediation' => true,
                ],
            ],
            'platinum' => [
                'name' => 'Platinum',
                'price' => 500000,
                'benefits' => [
                    'escrow_fee_discount' => 50,
                    'withdrawal_fee_discount' => 100,
                    'max_daily_transactions' => 100,
                    'max_transaction_amount' => 100000000,
                    'priority_support' => true,
                    'dispute_mediation' => true,
                    'dedicated_account_manager' => true,
                ],
            ],
        ];

        return $configs[$tier] ?? $configs['free'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if membership is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active' 
            && $this->expires_at > now();
    }

    /**
     * Get benefits for this membership.
     */
    public function getBenefits(): array
    {
        return self::getTierConfig($this->tier)['benefits'];
    }
}
