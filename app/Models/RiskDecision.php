<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskDecision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'subject_type',
        'subject_id',
        'input_snapshot',
        'snapshot_hash',
        'score',
        'action',
        'engine_version',
    ];

    protected $casts = [
        'input_snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
