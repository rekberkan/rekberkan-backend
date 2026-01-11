<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Domain\Money\Money;

final class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'available_balance',
        'locked_balance',
        'currency',
        // RiskEngine fields
        'frozen',
        'frozen_at',
        'withdrawal_delay_until',
        'enhanced_monitoring',
        'kyc_required',
    ];

    protected $casts = [
        'available_balance' => 'integer',
        'locked_balance' => 'integer',
        'frozen' => 'boolean',
        'frozen_at' => 'datetime',
        'withdrawal_delay_until' => 'datetime',
        'enhanced_monitoring' => 'boolean',
        'kyc_required' => 'boolean',
    ];

    protected $attributes = [
        'available_balance' => 0,
        'locked_balance' => 0,
        'currency' => 'IDR',
        'frozen' => false,
        'enhanced_monitoring' => false,
        'kyc_required' => false,
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // Business methods
    public function getTotalBalance(): Money
    {
        return Money::IDR($this->available_balance + $this->locked_balance);
    }

    public function getAvailableBalance(): Money
    {
        return Money::IDR($this->available_balance);
    }

    public function getLockedBalance(): Money
    {
        return Money::IDR($this->locked_balance);
    }

    public function hasAvailableBalance(Money $amount): bool
    {
        return $this->getAvailableBalance()->isGreaterThanOrEqualTo($amount);
    }

    /**
     * Check if wallet is frozen
     */
    public function isFrozen(): bool
    {
        return (bool) $this->frozen;
    }

    /**
     * Check if wallet has withdrawal delay
     */
    public function hasWithdrawalDelay(): bool
    {
        return $this->withdrawal_delay_until && $this->withdrawal_delay_until->isFuture();
    }

    /**
     * Check if wallet requires enhanced monitoring
     */
    public function requiresEnhancedMonitoring(): bool
    {
        return (bool) $this->enhanced_monitoring;
    }

    /**
     * Check if wallet requires KYC
     */
    public function requiresKyc(): bool
    {
        return (bool) $this->kyc_required;
    }

    /**
     * Lock for update to prevent race conditions
     * 
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function lockForUpdate(): self
    {
        return self::where('id', $this->id)->lockForUpdate()->firstOrFail();
    }
}
