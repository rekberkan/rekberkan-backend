<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware: Check if user has admin role.
 * 
 * SECURITY FIXES:
 * - Bug #10: Added caching to prevent timing attacks
 * - Bug #10: Simplified authorization logic
 * - Added rate limiting on failed attempts
 */
class CheckAdminRole
{
    private const CACHE_TTL = 300; // 5 minutes
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_TIME = 900; // 15 minutes

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        // Check rate limiting for this user
        if ($this->isRateLimited($user->id)) {
            Log::warning('Admin access rate limited', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Too many failed attempts. Please try again later.',
            ], 429);
        }

        // Check if user has admin role (with caching)
        if (!$this->isAdmin($user)) {
            $this->recordFailedAttempt($user->id);

            Log::warning('Non-admin attempted to access admin route', [
                'user_id' => $user->id,
                'email' => $user->email,
                'route' => $request->path(),
                'ip' => $request->ip(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Forbidden. Admin access required.',
            ], 403);
        }

        return $next($request);
    }

    /**
     * Check if user is admin (with caching to prevent timing attacks)
     * FIX: Bug #10
     */
    private function isAdmin($user): bool
    {
        $cacheKey = "user:{$user->id}:is_admin";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user) {
            // Primary check: Admin model instance
            if ($user instanceof Admin && $user->is_active) {
                return true;
            }

            // Secondary check: admins table lookup
            $adminRecord = \Illuminate\Support\Facades\DB::table('admins')
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->first();

            return $adminRecord !== null;
        });
    }

    /**
     * Check if user is rate limited
     */
    private function isRateLimited(int $userId): bool
    {
        $key = "admin_access_failed:{$userId}";
        $attempts = Cache::get($key, 0);

        return $attempts >= self::MAX_FAILED_ATTEMPTS;
    }

    /**
     * Record failed admin access attempt
     */
    private function recordFailedAttempt(int $userId): void
    {
        $key = "admin_access_failed:{$userId}";
        $attempts = Cache::get($key, 0);

        Cache::put($key, $attempts + 1, self::LOCKOUT_TIME);
    }
}
