<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Domain\Escrow\Enums\EscrowStatus;
use App\Domain\Money\Money;
use Illuminate\Support\Str;

final class Escrow extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'buyer_id',
        'seller_id',
        'buyer_wallet_id',
        'seller_wallet_id',
        'amount',
        'fee_amount',
        'currency',
        'status',
        'title',
        'description',
        'funded_at',
        'delivered_at',
        'released_at',
        'refunded_at',
        'disputed_at',
        'cancelled_at',
        'expired_at',
        'sla_auto_release_at',
        'sla_auto_refund_at',
        'auth_posting_batch_id',
        'settlement_posting_batch_id',
        'idempotency_key',
        'metadata',
    ];

    protected $casts = [
        'status' => EscrowStatus::class,
        'amount' => 'integer',
        'fee_amount' => 'integer',
        'funded_at' => 'datetime',
        'delivered_at' => 'datetime',
        'released_at' => 'datetime',
        'refunded_at' => 'datetime',
        'disputed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expired_at' => 'datetime',
        'sla_auto_release_at' => 'datetime',
        'sla_auto_refund_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'currency' => 'IDR',
        'status' => EscrowStatus::CREATED,
    ];

    public static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) Str::uuid();
            }
            
            // Calculate fee if not set
            if ($model->fee_amount === null) {
                $feePercentage = $model->tenant->getConfigValue('fee_percentage', 5.00);
                $model->fee_amount = (int) ($model->amount * ($feePercentage / 100));
            }

            // Set SLA deadlines
            if (!$model->sla_auto_release_at) {
                $hours = $model->tenant->getConfigValue('sla_auto_release_hours', 72);
                $model->sla_auto_release_at = now()->addHours($hours);
            }
            if (!$model->sla_auto_refund_at) {
                $hours = $model->tenant->getConfigValue('sla_auto_refund_hours', 168);
                $model->sla_auto_refund_at = now()->addHours($hours);
            }
        });
    }

    // Relationships
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function buyerWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'buyer_wallet_id');
    }

    public function sellerWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'seller_wallet_id');
    }

    public function timeline(): HasMany
    {
        return $this->hasMany(EscrowTimeline::class)->orderBy('created_at', 'desc');
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    // Business methods
    public function getAmount(): Money
    {
        return Money::IDR($this->amount);
    }

    public function getFeeAmount(): Money
    {
        return Money::IDR($this->fee_amount);
    }

    public function getNetAmount(): Money
    {
        return Money::IDR($this->amount - $this->fee_amount);
    }

    public function canTransitionTo(EscrowStatus $newStatus): bool
    {
        return $this->status->canTransitionTo($newStatus);
    }

    public function isOverdueSLA(): bool
    {
        if ($this->status === EscrowStatus::DELIVERED && $this->sla_auto_release_at !== null) {
            return now()->isAfter($this->sla_auto_release_at);
        }
        return false;
    }

    public function isExpired(): bool
    {
        if ($this->status === EscrowStatus::CREATED && $this->sla_auto_refund_at !== null) {
            return now()->isAfter($this->sla_auto_refund_at);
        }
        return false;
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
