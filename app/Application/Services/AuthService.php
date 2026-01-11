<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Models\User;
use App\Models\RefreshToken;
use App\Models\UserDevice;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;
use Carbon\Carbon;

final class AuthService
{
    private const ACCESS_TOKEN_TTL = 15; // minutes
    private const REFRESH_TOKEN_TTL = 43200; // minutes (30 days)

    /**
     * Register new user
     */
    public function register(int $tenantId, array $data): User
    {
        return DB::transaction(function () use ($tenantId, $data) {
            $user = User::create([
                'tenant_id' => $tenantId,
                'email' => $data['email'],
                'password' => $data['password'],
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
            ]);

            // Create wallet for user
            $user->wallet()->create([
                'tenant_id' => $tenantId,
                'available_balance' => 0,
                'locked_balance' => 0,
                'currency' => 'IDR',
            ]);

            Log::info('User registered', [
                'user_id' => $user->id,
                'tenant_id' => $tenantId,
                'email' => $user->email,
            ]);

            return $user;
        });
    }

    /**
     * Authenticate user and generate tokens
     */
    public function login(string $email, string $password, \Illuminate\Http\Request $request): array
    {
        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            $this->logSecurityEvent('login_failed', $email, $request);
            throw new \App\Exceptions\Auth\InvalidCredentialsException();
        }

        if ($user->isFrozen()) {
            $this->logSecurityEvent('login_frozen_account', $email, $request);
            throw new \App\Exceptions\Auth\AccountFrozenException();
        }

        return DB::transaction(function () use ($user, $request) {
            // Generate access token
            $accessToken = JWTAuth::fromUser($user);

            // Generate refresh token with device binding
            $deviceFingerprint = UserDevice::generateFingerprint($request);
            $refreshToken = $this->createRefreshToken($user, $deviceFingerprint);

            // Track device
            $this->trackDevice($user, $request, $deviceFingerprint);

            // Update last login
            $user->update(['last_login_at' => now()]);

            $this->logSecurityEvent('login_success', $user->email, $request, [
                'user_id' => $user->id,
            ]);

            return [
                'user' => $user,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken->id,
                'token_type' => 'Bearer',
                'expires_in' => self::ACCESS_TOKEN_TTL * 60,
            ];
        });
    }

    /**
     * Refresh access token with rotation
     */
    public function refresh(string $refreshTokenId, \Illuminate\Http\Request $request): array
    {
        $refreshToken = RefreshToken::find($refreshTokenId);

        if (!$refreshToken) {
            $this->logSecurityEvent('refresh_token_not_found', null, $request);
            throw new \App\Exceptions\Auth\InvalidRefreshTokenException();
        }

        // Detect token reuse (security violation)
        if ($refreshToken->isUsed()) {
            $this->logSecurityEvent('refresh_token_reuse_detected', null, $request, [
                'user_id' => $refreshToken->user_id,
                'token_family_id' => $refreshToken->token_family_id,
            ]);

            // Revoke entire token family
            $refreshToken->revokeFamilyTokens();

            throw new \App\Exceptions\Auth\TokenReuseDetectedException();
        }

        if (!$refreshToken->isValid()) {
            $this->logSecurityEvent('refresh_token_invalid', null, $request);
            throw new \App\Exceptions\Auth\InvalidRefreshTokenException();
        }

        // Verify device binding
        $deviceFingerprint = UserDevice::generateFingerprint($request);
        if ($refreshToken->device_fingerprint_hash !== $deviceFingerprint) {
            $this->logSecurityEvent('device_fingerprint_mismatch', null, $request, [
                'user_id' => $refreshToken->user_id,
            ]);
            throw new \App\Exceptions\Auth\DeviceMismatchException();
        }

        return DB::transaction(function () use ($refreshToken, $request, $deviceFingerprint) {
            $user = $refreshToken->user;

            // Mark old token as used
            $refreshToken->markAsUsed();

            // Generate new access token
            $accessToken = JWTAuth::fromUser($user);

            // Create new refresh token (rotation)
            $newRefreshToken = $this->createRefreshToken(
                $user,
                $deviceFingerprint,
                $refreshToken->token_family_id
            );

            $this->logSecurityEvent('token_refreshed', $user->email, $request, [
                'user_id' => $user->id,
            ]);

            return [
                'access_token' => $accessToken,
                'refresh_token' => $newRefreshToken->id,
                'token_type' => 'Bearer',
                'expires_in' => self::ACCESS_TOKEN_TTL * 60,
            ];
        });
    }

    /**
     * Logout and revoke tokens
     */
    public function logout(User $user, ?string $refreshTokenId = null): void
    {
        if ($refreshTokenId) {
            $refreshToken = RefreshToken::find($refreshTokenId);
            if ($refreshToken && $refreshToken->user_id === $user->id) {
                $refreshToken->revoke();
            }
        } else {
            // Revoke all user's refresh tokens
            RefreshToken::where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        }

        JWTAuth::invalidate(JWTAuth::getToken());

        Log::info('User logged out', ['user_id' => $user->id]);
    }

    /**
     * Create refresh token with device binding
     */
    private function createRefreshToken(
        User $user,
        string $deviceFingerprint,
        ?string $tokenFamilyId = null
    ): RefreshToken {
        return RefreshToken::create([
            'user_id' => $user->id,
            'token_family_id' => $tokenFamilyId,
            'device_fingerprint_hash' => $deviceFingerprint,
            'expires_at' => Carbon::now()->addMinutes(self::REFRESH_TOKEN_TTL),
        ]);
    }

    /**
     * Track user device
     */
    private function trackDevice(User $user, \Illuminate\Http\Request $request, string $fingerprint): void
    {
        $device = UserDevice::firstOrCreate(
            [
                'user_id' => $user->id,
                'device_fingerprint_hash' => $fingerprint,
            ],
            [
                'device_type' => $this->detectDeviceType($request),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'last_used_at' => now(),
            ]
        );

        if (!$device->wasRecentlyCreated) {
            $device->markAsUsed();
        }
    }

    private function detectDeviceType(\Illuminate\Http\Request $request): string
    {
        $userAgent = strtolower($request->userAgent() ?? '');

        if (str_contains($userAgent, 'mobile')) {
            return 'mobile';
        }
        if (str_contains($userAgent, 'tablet')) {
            return 'tablet';
        }
        return 'desktop';
    }

    /**
     * Log security events
     */
    private function logSecurityEvent(
        string $event,
        ?string $email,
        \Illuminate\Http\Request $request,
        array $context = []
    ): void {
        Log::channel('security')->warning($event, array_merge([
            'email' => $email,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => $request->attributes->get('request_id'),
        ], $context));
    }
}
