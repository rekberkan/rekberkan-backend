<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserBehaviorLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'user_behavior_log';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'event_type',
        'metadata',
        'ip_address',
        'user_agent',
        'severity',      // NEW: for RiskEngine
        'context',       // NEW: for RiskEngine
    ];

    protected $casts = [
        'metadata' => 'array',
        'context' => 'array',    // NEW: cast to array
        'created_at' => 'datetime',
    ];

    // Relationships
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Helper methods
    public function isCritical(): bool
    {
        return $this->severity === 'critical';
    }

    public function isHighSeverity(): bool
    {
        return in_array($this->severity, ['high', 'critical']);
    }
}
