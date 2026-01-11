<?php

namespace App\Http\Middleware;

use App\Models\Admin;
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
        if ($user instanceof Admin) {
            return (bool) $user->is_active;
        }

        if (method_exists($user, 'hasRole') && $user->hasRole('admin')) {
            return true;
        }

        return (bool) ($user->is_admin ?? false);
    }
}
