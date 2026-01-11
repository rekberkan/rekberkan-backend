<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MakerCheckerAction extends Model
{
    protected $fillable = [
        'action_type',
        'subject_type',
        'subject_id',
        'maker_id',
        'checker_id',
        'approval_status',
        'payload_snapshot',
        'maker_notes',
        'checker_notes',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'executed_at',
    ];

    protected $casts = [
        'payload_snapshot' => 'array',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    public function makerAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'maker_id');
    }

    public function checkerAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'checker_id');
    }
}
