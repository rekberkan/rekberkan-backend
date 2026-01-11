<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthService
{
    public function __construct(
        private BreachedPasswordService $breachedPasswordService,
        private DeviceFingerprintService $deviceFingerprintService
    ) {}

    /**
     * Register a new user with proper tenant validation
     */
    public function register(array $data, int $tenantId): array
    {
        // Validate tenant exists and is active
        $tenant = Tenant::where('id', $tenantId)
            ->where('status', 'active')
            ->first();

        if (!$tenant) {
            throw ValidationException::withMessages([
                'tenant_id' => ['Invalid or inactive tenant'],
            ]);
        }

        // Check if email is globally unique (prevent cross-tenant collision)
        $existingUser = User::withoutGlobalScope('tenant')
            ->where('email', $data['email'])
            ->first();

        if ($existingUser) {
            throw ValidationException::withMessages([
                'email' => ['The email has already been taken'],
            ]);
        }

        // Check password against breach database
        if ($this->breachedPasswordService->isPasswordBreached($data['password'])) {
            throw ValidationException::withMessages([
                'password' => [
                    'This password has been found in data breaches. Please choose a different password.'
                ],
            ]);
        }

        // Create user and wallet in transaction
        return DB::transaction(function () use ($data, $tenantId, $tenant) {
            // Create user
            $user = User::create([
                'tenant_id' => $tenantId,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'phone' => $data['phone'] ?? null,
                'status' => 'active',
            ]);

            // Create wallet
            $wallet = Wallet::create([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'balance' => 0,
                'currency' => 'IDR',
            ]);

            // Generate device fingerprint
            $deviceFingerprint = $this->deviceFingerprintService->generate(request());

            // Generate JWT with tenant claim
            $token = $this->generateTokenWithClaims($user, $deviceFingerprint);

            Log::info('User registered successfully', [
                'user_id' => $user->id,
                'tenant_id' => $tenantId,
                'email' => $user->email,
            ]);

            return [
                'user' => $user,
                'wallet' => $wallet,
                'token' => $token,
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl') * 60,
            ];
        });
    }

    /**
     * Login with tenant scoping
     */
    public function login(array $credentials, int $tenantId): array
    {
        // Validate tenant
        $tenant = Tenant::where('id', $tenantId)
            ->where('status', 'active')
            ->first();

        if (!$tenant) {
            throw ValidationException::withMessages([
                'tenant_id' => ['Invalid or inactive tenant'],
            ]);
        }

        // Find user with tenant scope (prevent cross-tenant login)
        $user = User::where('email', $credentials['email'])
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect'],
            ]);
        }

        // Generate device fingerprint
        $deviceFingerprint = $this->deviceFingerprintService->generate(request());

        // Generate token with tenant and device claims
        $token = $this->generateTokenWithClaims($user, $deviceFingerprint);

        // Update last login
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ]);

        Log::info('User logged in', [
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'ip' => request()->ip(),
        ]);

        return [
            'user' => $user->load('wallet'),
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => config('jwt.ttl') * 60,
        ];
    }

    /**
     * Logout with proper guard handling
     */
    public function logout(): void
    {
        try {
            // Check if token exists before invalidating
            $token = JWTAuth::getToken();
            
            if ($token) {
                JWTAuth::invalidate($token);
                
                Log::info('User logged out', [
                    'user_id' => auth()->id(),
                ]);
            }
        } catch (\Tymon\JWTAuth\Exceptions\TokenInvalidException $e) {
            // Token already invalid, silently continue
            Log::debug('Logout attempted with invalid token', [
                'error' => $e->getMessage(),
            ]);
        } catch (\Tymon\JWTAuth\Exceptions\JWTException $e) {
            // No token present, silently continue
            Log::debug('Logout attempted without token', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Refresh token with tenant binding validation
     */
    public function refresh(): array
    {
        try {
            $oldToken = JWTAuth::getToken();
            
            if (!$oldToken) {
                throw new \RuntimeException('No token provided');
            }

            // Get payload from old token
            $payload = JWTAuth::getPayload($oldToken);
            $userId = $payload->get('sub');
            $tenantId = $payload->get('tenant_id');
            $deviceFingerprint = $payload->get('device_fingerprint');

            // Validate user still exists and belongs to tenant
            $user = User::where('id', $userId)
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->first();

            if (!$user) {
                throw new \RuntimeException('User not found or inactive');
            }

            // Validate device fingerprint hasn't changed significantly
            $currentFingerprint = $this->deviceFingerprintService->generate(request());
            $similarity = $this->deviceFingerprintService->similarity(
                $deviceFingerprint,
                $currentFingerprint
            );

            if ($similarity < 70) {
                Log::warning('Device fingerprint mismatch on refresh', [
                    'user_id' => $userId,
                    'similarity' => $similarity,
                ]);
                throw new \RuntimeException('Device verification failed');
            }

            // Generate new token with same claims
            $newToken = $this->generateTokenWithClaims($user, $currentFingerprint);

            // Invalidate old token
            JWTAuth::invalidate($oldToken);

            return [
                'token' => $newToken,
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl') * 60,
            ];

        } catch (\Tymon\JWTAuth\Exceptions\JWTException $e) {
            Log::error('Token refresh failed', [
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to refresh token');
        }
    }

    /**
     * Generate JWT with custom claims
     */
    private function generateTokenWithClaims(User $user, string $deviceFingerprint): string
    {
        $customClaims = [
            'tenant_id' => $user->tenant_id,
            'device_fingerprint' => $deviceFingerprint,
            'roles' => $user->roles->pluck('name')->toArray(),
        ];

        return JWTAuth::claims($customClaims)->fromUser($user);
    }

    /**
     * Verify password change with breach check
     */
    public function changePassword(User $user, string $newPassword): void
    {
        // Check against breach database
        if ($this->breachedPasswordService->isPasswordBreached($newPassword)) {
            throw ValidationException::withMessages([
                'password' => [
                    'This password has been found in data breaches. Please choose a different password.'
                ],
            ]);
        }

        $user->update([
            'password' => Hash::make($newPassword),
        ]);

        Log::info('Password changed', [
            'user_id' => $user->id,
        ]);
    }
}
