<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignParticipation extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'campaign_id',
        'user_id',
        'escrow_id',
        'benefit_amount',
        'posting_batch_id',
        'status',
        'idempotency_key',
    ];

    protected $casts = [
        'benefit_amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function escrow(): BelongsTo
    {
        return $this->belongsTo(Escrow::class);
    }
}
