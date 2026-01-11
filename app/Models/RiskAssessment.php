<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Domain\Risk\Enums\RiskTier;

/**
 * Immutable risk assessment snapshot
 */
final class RiskAssessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'engine_version',
        'risk_score',
        'risk_tier',
        'signals',
        'actions_taken',
        'metadata',
        'assessed_at',
    ];

    protected $casts = [
        'risk_score' => 'integer',
        'risk_tier' => RiskTier::class,
        'signals' => 'array',
        'actions_taken' => 'array',
        'metadata' => 'array',
        'assessed_at' => 'datetime',
    ];

    public static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->assessed_at) {
                $model->assessed_at = now();
            }
        });

        static::updating(function () {
            throw new \RuntimeException('RiskAssessment records are immutable');
        });

        static::deleting(function () {
            throw new \RuntimeException('RiskAssessment records cannot be deleted');
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
