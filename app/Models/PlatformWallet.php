<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Domain\Money\Money;

final class PlatformWallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'available_balance',
        'locked_balance',
        'total_fees_collected',
        'currency',
    ];

    protected $casts = [
        'available_balance' => 'integer',
        'locked_balance' => 'integer',
        'total_fees_collected' => 'integer',
    ];

    protected $attributes = [
        'available_balance' => 0,
        'locked_balance' => 0,
        'total_fees_collected' => 0,
        'currency' => 'IDR',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function getAvailableBalance(): Money
    {
        return Money::IDR($this->available_balance);
    }

    public function lockForUpdate(): self
    {
        return self::where('id', $this->id)->lockForUpdate()->first();
    }
}
