<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Security\SecurityEventLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class JwtAuthService
{
    public function __construct(
        private SecurityEventLogger $securityLogger
    ) {}

    /**
     * Authenticate user and issue tokens.
     */
    public function login(
        string $email,
        string $password,
        ?string $deviceId = null,
        ?array $deviceMetadata = null
    ): array {
        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            $this->securityLogger->log(
                'login_failed',
                null,
                null,
                ['email' => $email],
                'medium',
                true
            );

            throw new \Exception('Invalid credentials');
        }

        // Check if account is active
        if (!$user->is_active) {
            throw new \Exception('Account is inactive');
        }

        // Generate device ID if not provided
        $deviceId = $deviceId ?? Str::uuid()->toString();

        // Bind device (with max device limit check)
        $this->bindDevice($user->id, $deviceId, $deviceMetadata);

        // Generate tokens
        $customClaims = [
            'tenant_id' => $user->tenant_id,
            'device_id' => $deviceId,
        ];

        $accessToken = JWTAuth::customClaims($customClaims)->fromUser($user);
        $refreshToken = $this->generateRefreshToken($user->id, $deviceId);

        // Log successful login
        $this->securityLogger->log(
            'login_success',
            $user->id,
            $user->tenant_id,
            ['device_id' => $deviceId]
        );

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => config('jwt.ttl') * 60,
            'user' => $user,
        ];
    }

    /**
     * Refresh access token with rotation.
     */
    public function refresh(string $refreshToken, string $deviceId): array
    {
        // Verify refresh token
        $tokenRecord = DB::table('refresh_tokens')
            ->where('token_hash', hash('sha256', $refreshToken))
            ->where('device_id', $deviceId)
            ->where('expires_at', '>', now())
            ->where('revoked_at', null)
            ->first();

        if (!$tokenRecord) {
            // Potential reuse attack
            $this->securityLogger->log(
                'refresh_token_reuse_detected',
                null,
                null,
                ['device_id' => $deviceId],
                'critical',
                true
            );

            // Revoke all tokens for this device
            $this->revokeDeviceTokens($deviceId);

            throw new \Exception('Invalid or expired refresh token');
        }

        $user = User::findOrFail($tokenRecord->user_id);

        // Revoke old refresh token (rotation)
        DB::table('refresh_tokens')
            ->where('id', $tokenRecord->id)
            ->update(['revoked_at' => now()]);

        // Generate new tokens
        $customClaims = [
            'tenant_id' => $user->tenant_id,
            'device_id' => $deviceId,
        ];

        $accessToken = JWTAuth::customClaims($customClaims)->fromUser($user);
        $newRefreshToken = $this->generateRefreshToken($user->id, $deviceId);

        $this->securityLogger->log(
            'token_refreshed',
            $user->id,
            $user->tenant_id,
            ['device_id' => $deviceId]
        );

        return [
            'access_token' => $accessToken,
            'refresh_token' => $newRefreshToken,
            'token_type' => 'Bearer',
            'expires_in' => config('jwt.ttl') * 60,
        ];
    }

    /**
     * Logout and revoke tokens.
     */
    public function logout(string $userId, string $deviceId): void
    {
        // Revoke refresh tokens for device
        DB::table('refresh_tokens')
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        // Blacklist current JWT
        try {
            JWTAuth::parseToken()->invalidate();
        } catch (\Exception $e) {
            // Token already invalid
        }

        $user = User::find($userId);
        $this->securityLogger->log(
            'logout',
            $userId,
            $user?->tenant_id,
            ['device_id' => $deviceId]
        );
    }

    /**
     * Generate refresh token.
     */
    private function generateRefreshToken(string $userId, string $deviceId): string
    {
        $token = Str::random(64);
        $tokenHash = hash('sha256', $token);

        DB::table('refresh_tokens')->insert([
            'id' => Str::uuid()->toString(),
            'user_id' => $userId,
            'device_id' => $deviceId,
            'token_hash' => $tokenHash,
            'expires_at' => now()->addMinutes(config('jwt.refresh_ttl')),
            'created_at' => now(),
        ]);

        return $token;
    }

    /**
     * Bind device to user.
     */
    private function bindDevice(string $userId, string $deviceId, ?array $metadata): void
    {
        // Check device limit
        $deviceCount = DB::table('user_devices')
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->count();

        $maxDevices = config('auth.security.device_binding.max_devices_per_user', 5);

        if ($deviceCount >= $maxDevices) {
            // Deactivate oldest device
            $oldestDevice = DB::table('user_devices')
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->orderBy('last_used_at')
                ->first();

            if ($oldestDevice) {
                DB::table('user_devices')
                    ->where('id', $oldestDevice->id)
                    ->update(['is_active' => false]);
            }
        }

        // Upsert device
        DB::table('user_devices')->updateOrInsert(
            ['device_id' => $deviceId, 'user_id' => $userId],
            [
                'metadata' => $metadata ? json_encode($metadata) : null,
                'last_used_at' => now(),
                'is_active' => true,
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Revoke all tokens for a device.
     */
    private function revokeDeviceTokens(string $deviceId): void
    {
        DB::table('refresh_tokens')
            ->where('device_id', $deviceId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}
