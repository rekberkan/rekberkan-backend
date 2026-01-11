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
    ];

    protected $casts = [
        'available_balance' => 'integer',
        'locked_balance' => 'integer',
    ];

    protected $attributes = [
        'available_balance' => 0,
        'locked_balance' => 0,
        'currency' => 'IDR',
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
     * Lock for update to prevent race conditions
     * 
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function lockForUpdate(): self
    {
        return self::where('id', $this->id)->lockForUpdate()->firstOrFail();
    }
}
