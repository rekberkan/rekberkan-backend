<?php

namespace App\Services;

use App\Models\RiskDecision;
use App\Models\User;
use App\Models\Escrow;
use App\Models\UserBehaviorLog;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RiskEngine
{
    public const VERSION = '1.0.0';

    public const ACTION_LOW = 'LOW';
    public const ACTION_MEDIUM = 'MEDIUM';
    public const ACTION_HIGH = 'HIGH';
    public const ACTION_CRITICAL = 'CRITICAL';

    public function evaluateUser(User $user): array
    {
        $signals = $this->collectUserSignals($user);
        $score = $this->calculateScore($signals);
        $action = $this->determineAction($score);

        $snapshotHash = hash('sha256', json_encode($signals));

        $decision = RiskDecision::create([
            'tenant_id' => $user->tenant_id,
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'input_snapshot' => $signals,
            'snapshot_hash' => $snapshotHash,
            'score' => $score,
            'action' => $action,
            'engine_version' => self::VERSION,
        ]);

        app(AuditService::class)->log([
            'event_type' => 'RISK_EVALUATION',
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'metadata' => [
                'decision_id' => $decision->id,
                'score' => $score,
                'action' => $action,
            ],
        ]);

        return [
            'score' => $score,
            'action' => $action,
            'decision_id' => $decision->id,
        ];
    }

    protected function collectUserSignals(User $user): array
    {
        $accountAgeInDays = $user->created_at->diffInDays(now());

        $escrowStats = DB::table('escrows')
            ->where('tenant_id', $user->tenant_id)
            ->where(function ($q) use ($user) {
                $q->where('buyer_id', $user->id)
                    ->orWhere('seller_id', $user->id);
            })
            ->selectRaw('
                COUNT(*) as total_escrows,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as disputed_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled_count
            ', [Escrow::STATUS_DISPUTED, Escrow::STATUS_CANCELLED])
            ->first();

        $voucherAbuseCount = UserBehaviorLog::where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('event_type', 'VOUCHER_REDEMPTION_FAILED')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $rapidWithdrawals = UserBehaviorLog::where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('event_type', 'RAPID_WITHDRAWAL')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        $deviceChanges = UserBehaviorLog::where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('event_type', 'DEVICE_CHANGE')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $ipChanges = UserBehaviorLog::where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('event_type', 'IP_CHANGE')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $disputeRatio = $escrowStats->total_escrows > 0
            ? ($escrowStats->disputed_count / $escrowStats->total_escrows)
            : 0;

        $cancelRatio = $escrowStats->total_escrows > 0
            ? ($escrowStats->cancelled_count / $escrowStats->total_escrows)
            : 0;

        return [
            'account_age_days' => $accountAgeInDays,
            'total_escrows' => $escrowStats->total_escrows ?? 0,
            'disputed_count' => $escrowStats->disputed_count ?? 0,
            'cancelled_count' => $escrowStats->cancelled_count ?? 0,
            'dispute_ratio' => round($disputeRatio, 4),
            'cancel_ratio' => round($cancelRatio, 4),
            'voucher_abuse_count' => $voucherAbuseCount,
            'rapid_withdrawals' => $rapidWithdrawals,
            'device_changes' => $deviceChanges,
            'ip_changes' => $ipChanges,
            'evaluated_at' => now()->toIso8601String(),
        ];
    }

    protected function calculateScore(array $signals): int
    {
        $score = 0;

        if ($signals['account_age_days'] < 7) {
            $score += 15;
        } elseif ($signals['account_age_days'] < 30) {
            $score += 10;
        }

        if ($signals['dispute_ratio'] > 0.5) {
            $score += 30;
        } elseif ($signals['dispute_ratio'] > 0.2) {
            $score += 20;
        } elseif ($signals['dispute_ratio'] > 0.1) {
            $score += 10;
        }

        if ($signals['cancel_ratio'] > 0.3) {
            $score += 20;
        } elseif ($signals['cancel_ratio'] > 0.15) {
            $score += 10;
        }

        if ($signals['voucher_abuse_count'] >= 5) {
            $score += 25;
        } elseif ($signals['voucher_abuse_count'] >= 3) {
            $score += 15;
        }

        if ($signals['rapid_withdrawals'] >= 3) {
            $score += 20;
        }

        if ($signals['device_changes'] >= 5) {
            $score += 15;
        }

        if ($signals['ip_changes'] >= 10) {
            $score += 15;
        } elseif ($signals['ip_changes'] >= 5) {
            $score += 10;
        }

        return min(100, $score);
    }

    protected function determineAction(int $score): string
    {
        return match (true) {
            $score >= 75 => self::ACTION_CRITICAL,
            $score >= 50 => self::ACTION_HIGH,
            $score >= 25 => self::ACTION_MEDIUM,
            default => self::ACTION_LOW,
        };
    }

    public function applyRiskAction(User $user, string $action): void
    {
        switch ($action) {
            case self::ACTION_MEDIUM:
                $user->update(['withdraw_delay_until' => now()->addHours(24)]);
                break;

            case self::ACTION_HIGH:
                $user->update([
                    'wallet_frozen_at' => now(),
                    'wallet_frozen_reason' => 'High risk score detected',
                ]);
                break;

            case self::ACTION_CRITICAL:
                $user->update([
                    'wallet_frozen_at' => now(),
                    'wallet_frozen_reason' => 'Critical risk score - KYC required',
                    'kyc_required' => true,
                    'status' => 'LOCKED',
                ]);
                break;
        }
    }
}
