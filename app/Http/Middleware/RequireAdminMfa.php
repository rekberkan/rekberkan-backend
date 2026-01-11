<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminMfa
{
    /**
     * Handle an incoming request.
     * Enforce MFA for all admin users
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Pastikan user terautentikasi
        if (!$user) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Authentication required',
            ], 401);
        }

        // Check if user is admin
        if (!$user->hasRole('admin') && !$user->hasRole('super_admin')) {
            return $next($request);
        }

        // Admin harus punya MFA enabled
        if (!$user->two_factor_enabled) {
            return response()->json([
                'error' => 'MFA Required',
                'message' => 'Multi-factor authentication is mandatory for admin accounts. Please enable MFA in your security settings.',
                'required_action' => 'enable_mfa',
            ], 403);
        }

        // Verify MFA token if accessing sensitive operations
        if ($this->isSensitiveOperation($request)) {
            $this->verifyMfaToken($request, $user);
        }

        return $next($request);
    }

    /**
     * Check if the request is for a sensitive operation
     */
    private function isSensitiveOperation(Request $request): bool
    {
        $sensitivePatterns = [
            'api/admin/users',
            'api/admin/roles',
            'api/admin/permissions',
            'api/admin/settings',
            'api/admin/tenants',
            'api/admin/financial',
        ];

        foreach ($sensitivePatterns as $pattern) {
            if ($request->is($pattern . '*')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify MFA token for sensitive operations
     */
    private function verifyMfaToken(Request $request, $user): void
    {
        $mfaToken = $request->header('X-MFA-Token');

        if (empty($mfaToken)) {
            abort(403, 'MFA token required for this operation');
        }

        // Verify TOTP token
        $google2fa = app('pragmarx.google2fa');
        $valid = $google2fa->verifyKey($user->two_factor_secret, $mfaToken);

        if (!$valid) {
            abort(403, 'Invalid MFA token');
        }
    }
}
