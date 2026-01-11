<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Domain\Ledger\Enums\AccountType;

/**
 * Account Balance Snapshot
 * Updated atomically with ledger postings
 * Source of truth: LedgerLine, this is for performance
 */
final class AccountBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'account_type',
        'account_id',
        'balance',
        'currency',
        'last_posting_batch_id',
    ];

    protected $casts = [
        'account_type' => AccountType::class,
        'balance' => 'integer',
    ];

    protected $attributes = [
        'balance' => 0,
        'currency' => 'IDR',
    ];

    // Relationships
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lastPostingBatch(): BelongsTo
    {
        return $this->belongsTo(PostingBatch::class, 'last_posting_batch_id');
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
