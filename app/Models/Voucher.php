<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Voucher extends Model
{
    protected $fillable = [
        'tenant_id',
        'code',
        'type',
        'value',
        'usage_limit',
        'per_user_limit',
        'usage_count',
        'valid_from',
        'valid_until',
        'status',
        'metadata',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(VoucherRedemption::class);
    }

    public function isValid(): bool
    {
        if ($this->status !== 'ACTIVE') {
            return false;
        }

        $now = now();

        if ($this->valid_from && $now->lt($this->valid_from)) {
            return false;
        }

        if ($this->valid_until && $now->gt($this->valid_until)) {
            return false;
        }

        if ($this->usage_limit && $this->usage_count >= $this->usage_limit) {
            return false;
        }

        return true;
    }

    public function canBeUsedByUser(int $userId): bool
    {
        $userRedemptionCount = $this->redemptions()
            ->where('user_id', $userId)
            ->count();

        return $userRedemptionCount < $this->per_user_limit;
    }
}
