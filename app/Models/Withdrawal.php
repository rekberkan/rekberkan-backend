<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Domain\Payment\Enums\PaymentStatus;
use Illuminate\Support\Str;

final class Withdrawal extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'user_id',
        'wallet_id',
        'amount',
        'currency',
        'bank_code',
        'account_number',
        'account_holder_name',
        'status',
        'gateway_transaction_id',
        'gateway_reference',
        'gateway_response',
        'completed_at',
        'failed_at',
        'failure_reason',
        'idempotency_key',
    ];

    protected $casts = [
        'amount' => 'integer',
        'status' => PaymentStatus::class,
        'gateway_response' => 'array',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    protected $attributes = [
        'currency' => 'IDR',
        'status' => PaymentStatus::PENDING,
    ];

    public static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    // Relationships
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    // Business methods
    public function markAsCompleted(array $gatewayResponse): void
    {
        $this->update([
            'status' => PaymentStatus::COMPLETED,
            'gateway_response' => $gatewayResponse,
            'completed_at' => now(),
        ]);
    }

    public function markAsFailed(string $reason, array $gatewayResponse = []): void
    {
        $this->update([
            'status' => PaymentStatus::FAILED,
            'failure_reason' => $reason,
            'gateway_response' => $gatewayResponse,
            'failed_at' => now(),
        ]);
    }

    public function isComplete(): bool
    {
        return $this->status->isComplete();
    }
}
