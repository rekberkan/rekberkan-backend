<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class AdminAuthenticate
{
    public function handle(Request $request, Closure $next, ?string $role = null)
    {
        $admin = $request->user();

        if (!$admin || !$admin->is_admin) {
            return response()->json([
                'type' => 'https://rekberkan.com/errors/admin-required',
                'title' => 'Admin Access Required',
                'status' => 403,
                'detail' => 'This endpoint requires administrator privileges.',
            ], 403);
        }

        // Check specific role if provided
        if ($role && !$admin->hasRole($role)) {
            return response()->json([
                'type' => 'https://rekberkan.com/errors/insufficient-permissions',
                'title' => 'Insufficient Permissions',
                'status' => 403,
                'detail' => "This action requires the {$role} role.",
            ], 403);
        }

        return $next($request);
    }
}
