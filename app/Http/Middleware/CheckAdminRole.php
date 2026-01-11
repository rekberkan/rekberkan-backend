<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware: Check if user has admin role.
 * 
 * Apply to admin routes for authorization.
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
        if (!$this->isAdmin($user)) {
            \Illuminate\Support\Facades\Log::warning('Non-admin attempted to access admin route', [
                'user_id' => $user->id,
                'email' => $user->email,
                'route' => $request->path(),
            ]);
            
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
        // If user is Admin model instance
        if ($user instanceof Admin) {
            return (bool) $user->is_active;
        }

        // Check if user has role method (for role-based systems)
        if (method_exists($user, 'hasRole') && $user->hasRole('admin')) {
            return true;
        }

        // Check is_admin field on user model
        if (property_exists($user, 'is_admin') && $user->is_admin) {
            return true;
        }

        // Check admins table for this user
        $adminRecord = \Illuminate\Support\Facades\DB::table('admins')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();
            
        return $adminRecord !== null;
    }
}
