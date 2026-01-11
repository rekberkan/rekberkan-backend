<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    /**
     * Resolve tenant from multiple sources with priority order.
     * 
     * Priority:
     * 1. Subdomain (tenant.rekberkan.com)
     * 2. X-Tenant-ID header
     * 3. JWT claim (tenant_id)
     * 4. Query parameter (for webhooks/callbacks)
     * 
     * Sets tenant context for RLS policies.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $this->resolveTenantId($request);

        if ($tenantId) {
            // Store in request attributes
            $request->attributes->set('tenant_id', $tenantId);

            // Set PostgreSQL session variable for RLS
            DB::statement("SET app.tenant_id = ?::bigint", [$tenantId]);

            // Add to log context
            \Illuminate\Support\Facades\Log::shareContext([
                'tenant_id' => $tenantId,
            ]);
        }

        return $next($request);
    }

    /**
     * Resolve tenant ID from request sources.
     */
    private function resolveTenantId(Request $request): ?string
    {
        // 1. Try subdomain
        $host = $request->getHost();
        if (preg_match('/^([a-z0-9-]+)\.rekberkan\./', $host, $matches)) {
            return $this->validateAndReturnTenantId($matches[1]);
        }

        // 2. Try X-Tenant-ID header
        if ($request->hasHeader('X-Tenant-ID')) {
            return $this->validateAndReturnTenantId($request->header('X-Tenant-ID'));
        }

        // 3. Try JWT claim
        $user = $request->user();
        if ($user && method_exists($user, 'tenant_id')) {
            return $user->tenant_id;
        }

        // 4. Try query parameter (for webhooks)
        if ($request->has('tenant_id')) {
            return $this->validateAndReturnTenantId($request->query('tenant_id'));
        }

        return null;
    }

    /**
     * Validate tenant ID format and existence.
     */
    private function validateAndReturnTenantId(mixed $tenantId): ?string
    {
        if (!is_string($tenantId)) {
            return null;
        }

        // Validate UUID format
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantId)) {
            return null;
        }

        return $tenantId;
    }
}
