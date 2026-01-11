<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Domain\Escrow\Enums\EscrowEvent;

/**
 * Immutable event log for escrow lifecycle
 */
final class EscrowTimeline extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'escrow_id',
        'event',
        'actor_type',
        'actor_id',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'event' => EscrowEvent::class,
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->created_at) {
                $model->created_at = now();
            }
        });

        static::updating(function () {
            throw new \RuntimeException('EscrowTimeline records are immutable');
        });

        static::deleting(function () {
            throw new \RuntimeException('EscrowTimeline records cannot be deleted');
        });
    }

    // Relationships
    public function escrow(): BelongsTo
    {
        return $this->belongsTo(Escrow::class);
    }

    public function actor(): BelongsTo
    {
        return $this->morphTo();
    }
}
