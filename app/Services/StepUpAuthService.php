<?php

namespace App\Services;

use App\Models\StepUpToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StepUpAuthService
{
    public function generate(
        int $tenantId,
        string $subjectType,
        int $subjectId,
        string $purpose,
        ?string $deviceFingerprint = null,
        int $ttlMinutes = 5
    ): string {
        $token = Str::random(64);
        $tokenHash = hash('sha256', $token);

        StepUpToken::create([
            'tenant_id' => $tenantId,
            'token_hash' => $tokenHash,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'purpose' => $purpose,
            'device_fingerprint' => $deviceFingerprint,
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        return $token;
    }

    public function verifyAndConsume(
        string $token,
        string $expectedSubjectType,
        int $expectedSubjectId,
        string $expectedPurpose
    ): void {
        $tokenHash = hash('sha256', $token);

        $stepUpToken = StepUpToken::where('token_hash', $tokenHash)
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->where('subject_type', $expectedSubjectType)
            ->where('subject_id', $expectedSubjectId)
            ->where('purpose', $expectedPurpose)
            ->first();

        if (!$stepUpToken) {
            throw new \Exception('Invalid or expired step-up token');
        }

        $stepUpToken->update([
            'used' => true,
            'used_at' => now(),
        ]);
    }

    public function cleanupExpired(): int
    {
        return StepUpToken::where('expires_at', '<', now()->subDay())->delete();
    }
}
