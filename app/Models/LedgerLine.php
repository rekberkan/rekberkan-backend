<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Domain\Ledger\Enums\AccountType;

/**
 * Ledger Line - Immutable double-entry ledger
 * Insert-only, enforced by DB triggers
 */
final class LedgerLine extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'posting_batch_id',
        'tenant_id',
        'account_type',
        'account_id',
        'debit_amount',
        'credit_amount',
        'balance_after',
        'currency',
        'description',
        'created_at',
    ];

    protected $casts = [
        'account_type' => AccountType::class,
        'debit_amount' => 'integer',
        'credit_amount' => 'integer',
        'balance_after' => 'integer',
        'created_at' => 'datetime',
    ];

    protected $attributes = [
        'currency' => 'IDR',
        'debit_amount' => 0,
        'credit_amount' => 0,
    ];

    public static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->created_at) {
                $model->created_at = now();
            }
        });

        static::updating(function () {
            throw new \RuntimeException('LedgerLine records are immutable');
        });

        static::deleting(function () {
            throw new \RuntimeException('LedgerLine records cannot be deleted');
        });
    }

    // Relationships
    public function postingBatch(): BelongsTo
    {
        return $this->belongsTo(PostingBatch::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // Helper methods
    public function isDebit(): bool
    {
        return $this->debit_amount > 0;
    }

    public function isCredit(): bool
    {
        return $this->credit_amount > 0;
    }

    public function getAmount(): int
    {
        return $this->isDebit() ? $this->debit_amount : $this->credit_amount;
    }
}
