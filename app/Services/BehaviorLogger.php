<?php

namespace App\Services;

use App\Models\UserBehaviorLog;
use Illuminate\Http\Request;

class BehaviorLogger
{
    public function log(
        int $tenantId,
        ?int $userId,
        string $eventType,
        ?array $metadata = null,
        ?Request $request = null
    ): void {
        UserBehaviorLog::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'event_type' => $eventType,
            'metadata' => $metadata,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    public function logVoucherRedemptionAttempt(
        int $tenantId,
        int $userId,
        string $voucherCode,
        bool $success,
        ?string $failureReason = null,
        ?Request $request = null
    ): void {
        $this->log(
            tenantId: $tenantId,
            userId: $userId,
            eventType: $success ? 'VOUCHER_REDEMPTION_SUCCESS' : 'VOUCHER_REDEMPTION_FAILED',
            metadata: [
                'voucher_code' => $voucherCode,
                'failure_reason' => $failureReason,
            ],
            request: $request
        );
    }

    public function logRapidWithdrawal(
        int $tenantId,
        int $userId,
        int $withdrawalId,
        ?Request $request = null
    ): void {
        $this->log(
            tenantId: $tenantId,
            userId: $userId,
            eventType: 'RAPID_WITHDRAWAL',
            metadata: ['withdrawal_id' => $withdrawalId],
            request: $request
        );
    }

    public function logDeviceChange(
        int $tenantId,
        int $userId,
        string $oldFingerprint,
        string $newFingerprint,
        ?Request $request = null
    ): void {
        $this->log(
            tenantId: $tenantId,
            userId: $userId,
            eventType: 'DEVICE_CHANGE',
            metadata: [
                'old_fingerprint' => $oldFingerprint,
                'new_fingerprint' => $newFingerprint,
            ],
            request: $request
        );
    }

    public function logIpChange(
        int $tenantId,
        int $userId,
        string $oldIp,
        string $newIp,
        ?Request $request = null
    ): void {
        $this->log(
            tenantId: $tenantId,
            userId: $userId,
            eventType: 'IP_CHANGE',
            metadata: [
                'old_ip' => $oldIp,
                'new_ip' => $newIp,
            ],
            request: $request
        );
    }
}
