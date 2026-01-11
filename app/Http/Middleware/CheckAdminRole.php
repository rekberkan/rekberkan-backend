<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * NEW MIDDLEWARE: Check if user has admin role.
 * 
 * Apply to admin routes untuk authorization.
 */
class CheckAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        // Check if user has admin role
        // TODO: Implement proper role check based on your user model
        if (!$this->isAdmin($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden. Admin access required.',
            ], 403);
        }

        return $next($request);
    }

    /**
     * Check if user is admin.
     */
    private function isAdmin($user): bool
    {
        // Option 1: Check role field
        if (isset($user->role) && $user->role === 'admin') {
            return true;
        }

        // Option 2: Check is_admin boolean field
        if (isset($user->is_admin) && $user->is_admin === true) {
            return true;
        }

        // Option 3: Check via roles table (if using Spatie/Laravel Permission)
        // if ($user->hasRole('admin')) {
        //     return true;
        // }

        return false;
    }
}
