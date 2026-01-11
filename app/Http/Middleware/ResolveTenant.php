<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    /**
     * Handle an incoming request with proper tenant resolution and validation.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $this->resolveTenantId($request);

        if (!$tenantId) {
            return response()->json([
                'error' => 'Tenant Required',
                'message' => 'Valid tenant identification is required',
            ], 400);
        }

        // Validate tenant exists and is active
        $tenant = $this->getTenant($tenantId);

        if (!$tenant) {
            Log::warning('Attempted access with invalid tenant', [
                'tenant_id' => $tenantId,
                'ip' => $request->ip(),
                'user' => $request->user()?->id,
            ]);

            return response()->json([
                'error' => 'Invalid Tenant',
                'message' => 'The specified tenant does not exist or is inactive',
            ], 404);
        }

        // Set tenant context
        app()->instance('tenant', $tenant);
        $request->merge(['tenant_id' => $tenant->id]);
        $request->attributes->set('tenant', $tenant);

        // Set tenant ID for query scope
        config(['app.current_tenant_id' => $tenant->id]);

        return $next($request);
    }

    /**
     * Resolve tenant ID from multiple sources with proper priority
     */
    private function resolveTenantId(Request $request): ?int
    {
        // Priority 1: JWT token tenant claim (most secure)
        if ($user = $request->user()) {
            if (isset($user->tenant_id) && $user->tenant_id > 0) {
                return $user->tenant_id;
            }
        }

        // Priority 2: X-Tenant-ID header (consistent casing)
        $headerTenantId = $request->header('X-Tenant-ID');
        if ($headerTenantId !== null) {
            // Validate it's a positive integer
            if (is_numeric($headerTenantId) && (int) $headerTenantId > 0) {
                return (int) $headerTenantId;
            }
            
            // If header exists but invalid, log and reject
            Log::warning('Invalid X-Tenant-ID header', [
                'value' => $headerTenantId,
                'type' => gettype($headerTenantId),
            ]);
            return null;
        }

        // Priority 3: Subdomain resolution (supports both numeric and slug)
        $subdomain = $this->extractSubdomain($request);
        if ($subdomain) {
            return $this->resolveTenantFromSubdomain($subdomain);
        }

        return null;
    }

    /**
     * Extract subdomain from request
     */
    private function extractSubdomain(Request $request): ?string
    {
        $host = $request->getHost();
        $baseDomain = config('app.base_domain', 'rekberkan.com');

        // Remove base domain to get subdomain
        if (str_ends_with($host, '.' . $baseDomain)) {
            $subdomain = str_replace('.' . $baseDomain, '', $host);
            return $subdomain !== '' ? $subdomain : null;
        }

        // Handle localhost/IP for development
        if (app()->environment('local')) {
            $parts = explode('.', $host);
            if (count($parts) > 1) {
                return $parts[0];
            }
        }

        return null;
    }

    /**
     * Resolve tenant from subdomain (supports slug or numeric ID)
     */
    private function resolveTenantFromSubdomain(string $subdomain): ?int
    {
        // Try numeric ID first
        if (is_numeric($subdomain)) {
            $tenantId = (int) $subdomain;
            if ($tenantId > 0) {
                return $tenantId;
            }
        }

        // Try slug lookup with caching
        return Cache::remember(
            "tenant:slug:{$subdomain}",
            now()->addHour(),
            fn() => Tenant::where('slug', $subdomain)
                ->where('status', 'active')
                ->value('id')
        );
    }

    /**
     * Get tenant with caching and validation
     */
    private function getTenant(int $tenantId): ?Tenant
    {
        return Cache::remember(
            "tenant:id:{$tenantId}",
            now()->addMinutes(15),
            function () use ($tenantId) {
                return Tenant::where('id', $tenantId)
                    ->where('status', 'active')
                    ->first();
            }
        );
    }
}
