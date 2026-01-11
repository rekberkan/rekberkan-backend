<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisputeAction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'dispute_id',
        'action_type',
        'maker_admin_id',
        'checker_admin_id',
        'approval_status',
        'payload_snapshot',
        'snapshot_hash',
        'maker_notes',
        'checker_notes',
        'submitted_at',
        'approved_at',
        'executed_at',
        'posting_batch_id',
    ];

    protected $casts = [
        'payload_snapshot' => 'array',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    public function makerAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'maker_admin_id');
    }

    public function checkerAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'checker_admin_id');
    }
}
