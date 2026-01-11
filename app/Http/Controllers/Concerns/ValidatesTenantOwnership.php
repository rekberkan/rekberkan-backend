<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

/**
 * Trait for validating tenant ownership in controllers
 */
trait ValidatesTenantOwnership
{
    /**
     * Validate that a model belongs to the current tenant
     */
    protected function validateTenantOwnership(Model $model, ?int $tenantId = null): void
    {
        $tenantId = $tenantId ?? $this->getCurrentTenantId();

        if (!$tenantId) {
            abort(400, 'Tenant context is required');
        }

        // Check if model has tenant_id property
        if (!isset($model->tenant_id)) {
            \Log::error('Model does not have tenant_id', [
                'model' => get_class($model),
                'id' => $model->id,
            ]);
            abort(500, 'Internal server error');
        }

        if ($model->tenant_id !== $tenantId) {
            \Log::warning('Tenant ownership validation failed', [
                'model' => get_class($model),
                'model_id' => $model->id,
                'model_tenant_id' => $model->tenant_id,
                'current_tenant_id' => $tenantId,
                'user_id' => auth()->id(),
            ]);

            abort(404, 'Resource not found');
        }
    }

    /**
     * Validate user belongs to current tenant
     */
    protected function validateUserTenant(?int $tenantId = null): void
    {
        $user = auth()->user();
        $tenantId = $tenantId ?? $this->getCurrentTenantId();

        if (!$user || $user->tenant_id !== $tenantId) {
            abort(403, 'Access denied');
        }
    }

    /**
     * Get current tenant ID from request or context
     */
    protected function getCurrentTenantId(): ?int
    {
        // From middleware-set attribute
        if ($tenant = request()->attributes->get('tenant')) {
            return $tenant->id;
        }

        // From config
        if ($tenantId = config('app.current_tenant_id')) {
            return (int) $tenantId;
        }

        // From authenticated user
        if ($user = auth()->user()) {
            return $user->tenant_id ?? null;
        }

        return null;
    }

    /**
     * Get current tenant model
     */
    protected function getCurrentTenant()
    {
        if ($tenant = request()->attributes->get('tenant')) {
            return $tenant;
        }

        if (app()->bound('tenant')) {
            return app('tenant');
        }

        return null;
    }

    /**
     * Return JSON error for tenant validation failure
     */
    protected function tenantValidationError(string $message = 'Resource not found'): JsonResponse
    {
        return response()->json([
            'error' => 'Forbidden',
            'message' => $message,
        ], 404); // Use 404 to avoid information leakage
    }
}
