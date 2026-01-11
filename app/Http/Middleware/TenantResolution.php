<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class TenantResolution
{
    public function handle(Request $request, Closure $next)
    {
        $tenantId = $this->resolveTenantId($request);

        if (!$tenantId) {
            return response()->json([
                'type' => 'https://rekberkan.com/errors/tenant-required',
                'title' => 'Tenant Required',
                'status' => 400,
                'detail' => 'Tenant context could not be determined.',
            ], 400);
        }

        // Store tenant context
        $request->attributes->set('tenant_id', $tenantId);

        // Load tenant configuration from cache
        $tenantConfig = Cache::remember(
            "tenant_config:{$tenantId}",
            now()->addHours(1),
            fn() => DB::table('tenants')->where('id', $tenantId)->first()
        );

        if (!$tenantConfig || !$tenantConfig->is_active) {
            return response()->json([
                'type' => 'https://rekberkan.com/errors/tenant-inactive',
                'title' => 'Tenant Inactive',
                'status' => 403,
                'detail' => 'This tenant is currently inactive.',
            ], 403);
        }

        $request->attributes->set('tenant_config', $tenantConfig);

        // Set PostgreSQL RLS variable for tenant isolation
        DB::statement("SET LOCAL app.current_tenant_id = ?", [$tenantId]);

        return $next($request);
    }

    private function resolveTenantId(Request $request): ?int
    {
        // Priority 1: JWT claim
        if ($request->user() && isset($request->user()->tenant_id)) {
            return $request->user()->tenant_id;
        }

        // Priority 2: Header
        if ($request->hasHeader('X-Tenant-Id')) {
            return (int) $request->header('X-Tenant-Id');
        }

        // Priority 3: Subdomain
        $host = $request->getHost();
        if (preg_match('/^([a-z0-9-]+)\.rekberkan\./', $host, $matches)) {
            return Cache::remember(
                "tenant_subdomain:{$matches[1]}",
                now()->addHours(24),
                fn() => DB::table('tenants')
                    ->where('subdomain', $matches[1])
                    ->value('id')
            );
        }

        return null;
    }
}
