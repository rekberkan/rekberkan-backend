<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trait for models that belong to a tenant
 * Provides automatic tenant scoping and validation
 */
trait BelongsToTenant
{
    /**
     * Boot the trait
     */
    protected static function bootBelongsToTenant(): void
    {
        // Automatically apply tenant scope to all queries
        static::addGlobalScope('tenant', function (Builder $builder) {
            if ($tenantId = self::getCurrentTenantId()) {
                $builder->where($builder->getModel()->getTable() . '.tenant_id', $tenantId);
            }
        });

        // Automatically set tenant_id when creating
        static::creating(function (Model $model) {
            if (!$model->tenant_id && $tenantId = self::getCurrentTenantId()) {
                $model->tenant_id = $tenantId;
            }

            // Validate tenant_id is set
            if (!$model->tenant_id || $model->tenant_id <= 0) {
                throw new \RuntimeException('Tenant ID is required and must be positive');
            }
        });

        // Prevent tenant_id modification
        static::updating(function (Model $model) {
            if ($model->isDirty('tenant_id')) {
                throw new \RuntimeException('Tenant ID cannot be modified');
            }
        });
    }

    /**
     * Get the tenant relationship
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get current tenant ID from context
     */
    protected static function getCurrentTenantId(): ?int
    {
        // Try from config (set by middleware)
        if ($tenantId = config('app.current_tenant_id')) {
            return (int) $tenantId;
        }

        // Try from app instance
        if (app()->bound('tenant')) {
            $tenant = app('tenant');
            return $tenant?->id;
        }

        // Try from authenticated user
        if ($user = auth()->user()) {
            return $user->tenant_id ?? null;
        }

        return null;
    }

    /**
     * Query without tenant scope
     */
    public static function withoutTenantScope(): Builder
    {
        return static::withoutGlobalScope('tenant');
    }

    /**
     * Verify model belongs to specific tenant
     */
    public function belongsToTenant(int $tenantId): bool
    {
        return $this->tenant_id === $tenantId;
    }
}
