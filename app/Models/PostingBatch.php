<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Domain\Ledger\Enums\MTIPhase;
use Illuminate\Support\Str;

/**
 * Posting Batch - Groups ledger lines for atomic transactions
 * Immutable once posted
 */
final class PostingBatch extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'rrn',
        'stan',
        'mti_phase',
        'idempotency_key',
        'total_debits',
        'total_credits',
        'posted_at',
        'metadata',
    ];

    protected $casts = [
        'mti_phase' => MTIPhase::class,
        'total_debits' => 'integer',
        'total_credits' => 'integer',
        'posted_at' => 'datetime',
        'metadata' => 'array',
    ];

    public static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) Str::uuid();
            }
            $model->posted_at = now();
        });

        static::updating(function () {
            throw new \RuntimeException('PostingBatch records are immutable');
        });

        static::deleting(function () {
            throw new \RuntimeException('PostingBatch records cannot be deleted');
        });
    }

    // Relationships
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function ledgerLines(): HasMany
    {
        return $this->hasMany(LedgerLine::class);
    }

    // Business logic
    public function isBalanced(): bool
    {
        return $this->total_debits === $this->total_credits;
    }
}
