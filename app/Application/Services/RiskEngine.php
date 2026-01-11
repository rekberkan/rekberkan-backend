<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Models\User;
use App\Models\RiskAssessment;
use App\Models\UserBehaviorLog;
use App\Models\Escrow;
use App\Models\Withdrawal;
use App\Domain\Risk\Enums\RiskTier;
use App\Domain\Risk\Enums\RiskSignal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class RiskEngine
{
    private const ENGINE_VERSION = '1.0.0';
    private const MAX_SCORE = 100;
    private const MIN_SCORE = 0;

    /**
     * Assess user risk deterministically
     */
    public function assess(User $user): RiskAssessment
    {
        return DB::transaction(function () use ($user) {
            $signals = $this->collectSignals($user);
            $score = $this->calculateScore($signals);
            $tier = RiskTier::fromScore($score);
            $actions = $tier->getActions();

            // Create immutable assessment
            $assessment = RiskAssessment::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'engine_version' => self::ENGINE_VERSION,
                'risk_score' => $score,
                'risk_tier' => $tier,
                'signals' => $signals,
                'actions_taken' => $actions,
                'metadata' => [
                    'account_age_days' => $user->created_at->diffInDays(now()),
                    'total_escrows' => $this->getTotalEscrows($user),
                    'total_disputes' => $this->getTotalDisputes($user),
                ],
            ]);

            // Execute actions
            $this->executeActions($user, $tier, $actions);

            Log::info('Risk assessment completed', [
                'user_id' => $user->id,
                'score' => $score,
                'tier' => $tier->value,
                'actions' => $actions,
            ]);

            return $assessment;
        });
    }

    /**
     * Collect risk signals deterministically
     */
    private function collectSignals(User $user): array
    {
        $signals = [];

        // Signal: Account age (negative weight)
        $accountAgeDays = $user->created_at->diffInDays(now());
        if ($accountAgeDays < 30) {
            $signals[RiskSignal::ACCOUNT_AGE->value] = [
                'value' => $accountAgeDays,
                'weight' => RiskSignal::ACCOUNT_AGE->getWeight(),
            ];
        }

        // Signal: Dispute ratio
        $totalEscrows = $this->getTotalEscrows($user);
        $totalDisputes = $this->getTotalDisputes($user);
        if ($totalEscrows > 0) {
            $disputeRatio = $totalDisputes / $totalEscrows;
            if ($disputeRatio > 0.1) { // 10% threshold
                $signals[RiskSignal::DISPUTE_RATIO->value] = [
                    'value' => round($disputeRatio * 100, 2),
                    'weight' => RiskSignal::DISPUTE_RATIO->getWeight(),
                ];
            }
        }

        // Signal: Cancel ratio
        $totalCancelled = $this->getTotalCancelled($user);
        if ($totalEscrows > 0) {
            $cancelRatio = $totalCancelled / $totalEscrows;
            if ($cancelRatio > 0.2) { // 20% threshold
                $signals[RiskSignal::CANCEL_RATIO->value] = [
                    'value' => round($cancelRatio * 100, 2),
                    'weight' => RiskSignal::CANCEL_RATIO->getWeight(),
                ];
            }
        }

        // Signal: Rapid withdrawals
        $recentWithdrawals = Withdrawal::where('user_id', $user->id)
            ->where('created_at', '>', now()->subHours(24))
            ->count();
        if ($recentWithdrawals > 5) {
            $signals[RiskSignal::RAPID_WITHDRAWAL->value] = [
                'value' => $recentWithdrawals,
                'weight' => RiskSignal::RAPID_WITHDRAWAL->getWeight(),
            ];
        }

        // Signal: Voucher abuse
        $voucherAbuse = $this->getVoucherAbuseCount($user);
        if ($voucherAbuse > 0) {
            $signals[RiskSignal::VOUCHER_ABUSE->value] = [
                'value' => $voucherAbuse,
                'weight' => RiskSignal::VOUCHER_ABUSE->getWeight(),
            ];
        }

        // Signal: Device change frequency
        $deviceChanges = $this->getDeviceChangeCount($user);
        if ($deviceChanges > 3) {
            $signals[RiskSignal::DEVICE_CHANGE_FREQUENCY->value] = [
                'value' => $deviceChanges,
                'weight' => RiskSignal::DEVICE_CHANGE_FREQUENCY->getWeight(),
            ];
        }

        // Signal: Large escrow pattern
        $largeEscrows = $this->getLargeEscrowCount($user);
        if ($largeEscrows > 0 && $totalEscrows > 0 && ($largeEscrows / $totalEscrows) > 0.5) {
            $signals[RiskSignal::LARGE_ESCROW_PATTERN->value] = [
                'value' => $largeEscrows,
                'weight' => RiskSignal::LARGE_ESCROW_PATTERN->getWeight(),
            ];
        }

        return $signals;
    }

    /**
     * Calculate risk score deterministically
     */
    private function calculateScore(array $signals): int
    {
        $baseScore = 0;

        foreach ($signals as $signal) {
            $baseScore += $signal['weight'];
        }

        // Clamp between MIN and MAX
        return max(self::MIN_SCORE, min(self::MAX_SCORE, $baseScore));
    }

    /**
     * Execute risk tier actions
     */
    private function executeActions(User $user, RiskTier $tier, array $actions): void
    {
        foreach ($actions as $action) {
            match($action) {
                'delay_withdrawal_24h' => $this->delayWithdrawals($user, 24),
                'freeze_wallet' => $this->freezeWallet($user),
                'require_kyc' => $this->requireKYC($user),
                'permanent_lock' => $this->permanentLock($user),
                'enhanced_monitoring' => $this->enableEnhancedMonitoring($user),
                'admin_review' => $this->flagForAdminReview($user),
                'fraud_investigation' => $this->triggerFraudInvestigation($user),
                default => null,
            };
        }
    }

    // Action implementations
    private function delayWithdrawals(User $user, int $hours): void
    {
        $user->update([
            'withdrawal_delay_until' => now()->addHours($hours),
        ]);
    }

    private function freezeWallet(User $user): void
    {
        $user->wallet->update(['frozen' => true]);
        $this->logBehavior($user, 'WALLET_FROZEN', 'HIGH');
    }

    private function requireKYC(User $user): void
    {
        $user->update(['kyc_required' => true]);
        $this->logBehavior($user, 'KYC_REQUIRED', 'MEDIUM');
    }

    private function permanentLock(User $user): void
    {
        $user->update(['frozen' => true, 'permanent_lock' => true]);
        $this->logBehavior($user, 'PERMANENT_LOCK', 'CRITICAL');
    }

    private function enableEnhancedMonitoring(User $user): void
    {
        $user->update(['enhanced_monitoring' => true]);
    }

    private function flagForAdminReview(User $user): void
    {
        $this->logBehavior($user, 'ADMIN_REVIEW_REQUIRED', 'HIGH');
    }

    private function triggerFraudInvestigation(User $user): void
    {
        $this->logBehavior($user, 'FRAUD_INVESTIGATION', 'CRITICAL');
    }

    // Helper methods for signal collection
    private function getTotalEscrows(User $user): int
    {
        return Escrow::where(function ($q) use ($user) {
            $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id);
        })->count();
    }

    private function getTotalDisputes(User $user): int
    {
        return Escrow::where(function ($q) use ($user) {
            $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id);
        })->where('status', 'DISPUTED')->count();
    }

    private function getTotalCancelled(User $user): int
    {
        return Escrow::where(function ($q) use ($user) {
            $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id);
        })->where('status', 'CANCELLED')->count();
    }

    private function getVoucherAbuseCount(User $user): int
    {
        return UserBehaviorLog::where('user_id', $user->id)
            ->where('event_type', 'VOUCHER_ABUSE')
            ->count();
    }

    private function getDeviceChangeCount(User $user): int
    {
        return UserBehaviorLog::where('user_id', $user->id)
            ->where('event_type', 'DEVICE_CHANGE')
            ->where('created_at', '>', now()->subDays(30))
            ->count();
    }

    private function getLargeEscrowCount(User $user): int
    {
        // Large = > 10,000,000 (100k IDR)
        return Escrow::where(function ($q) use ($user) {
            $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id);
        })->where('amount', '>', 10000000)->count();
    }

    /**
     * Log behavior event
     */
    public function logBehavior(
        User $user,
        string $eventType,
        string $severity,
        array $context = []
    ): void {
        UserBehaviorLog::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'event_type' => $eventType,
            'severity' => $severity,
            'context' => $context,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
