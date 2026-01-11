<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantExists
{
    /**
     * Ensure the resolved tenant exists and is active.
     * Must run after ResolveTenant middleware.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->attributes->get('tenant_id');

        if (!$tenantId) {
            return response()->json([
                'error' => 'Tenant not specified',
                'message' => 'A valid tenant identifier is required',
            ], 400);
        }

        // Check if tenant exists and is active
        $tenant = DB::table('tenants')
            ->where('id', $tenantId)
            ->where('is_active', true)
            ->first();

        if (!$tenant) {
            return response()->json([
                'error' => 'Tenant not found or inactive',
                'message' => 'The specified tenant does not exist or has been deactivated',
            ], 404);
        }

        return $next($request);
    }
}
