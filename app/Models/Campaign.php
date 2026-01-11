<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'description',
        'type',
        'budget_total',
        'budget_used',
        'max_participants',
        'current_participants',
        'starts_at',
        'ends_at',
        'status',
        'rules',
        'metadata',
    ];

    protected $casts = [
        'budget_total' => 'decimal:2',
        'budget_used' => 'decimal:2',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'rules' => 'array',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function participations(): HasMany
    {
        return $this->hasMany(CampaignParticipation::class);
    }

    public function isActive(): bool
    {
        if ($this->status !== 'ACTIVE') {
            return false;
        }

        $now = now();

        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }

        if ($this->ends_at && $now->gt($this->ends_at)) {
            return false;
        }

        if ($this->budget_total && $this->budget_used >= $this->budget_total) {
            return false;
        }

        if ($this->max_participants && $this->current_participants >= $this->max_participants) {
            return false;
        }

        return true;
    }
}
