<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Domain\Ledger\Enums\MTIPhase;
use App\Domain\Ledger\Enums\ProcessingCode;
use App\Domain\Ledger\Enums\ResponseCode;

/**
 * ISO 8583-style Financial Message
 * Immutable record of transaction lifecycle
 */
final class FinancialMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'mti_phase',
        'processing_code',
        'stan',
        'rrn',
        'idempotency_key',
        'amount',
        'currency',
        'related_entity_type',
        'related_entity_id',
        'originating_channel',
        'auth_id',
        'capture_id',
        'reversal_id',
        'response_code',
        'response_message',
        'metadata',
    ];

    protected $casts = [
        'mti_phase' => MTIPhase::class,
        'processing_code' => ProcessingCode::class,
        'response_code' => ResponseCode::class,
        'amount' => 'integer',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'currency' => 'IDR',
    ];

    // Prevent updates and deletes
    public static function boot(): void
    {
        parent::boot();

        static::updating(function () {
            throw new \RuntimeException('FinancialMessage records are immutable');
        });

        static::deleting(function () {
            throw new \RuntimeException('FinancialMessage records cannot be deleted');
        });
    }

    // Relationships
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function postingBatches(): HasMany
    {
        return $this->hasMany(PostingBatch::class, 'rrn', 'rrn');
    }

    // Scopes
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeByRRN($query, string $rrn)
    {
        return $query->where('rrn', $rrn);
    }

    public function scopeBySTAN($query, string $stan)
    {
        return $query->where('stan', $stan);
    }
}
