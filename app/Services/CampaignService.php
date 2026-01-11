<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignParticipation;
use App\Models\User;
use App\Models\Escrow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CampaignService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    public function getActiveCampaigns(?int $tenantId = null)
    {
        $now = now();

        $query = Campaign::query()
            ->where('status', 'ACTIVE')
            ->where(function ($builder) use ($now) {
                $builder->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($builder) use ($now) {
                $builder->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $now);
            })
            ->where(function ($builder) {
                $builder->whereNull('budget_total')
                    ->orWhereColumn('budget_used', '<', 'budget_total');
            })
            ->where(function ($builder) {
                $builder->whereNull('max_participants')
                    ->orWhereColumn('current_participants', '<', 'max_participants');
            });

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->orderByDesc('starts_at')->get();
    }

    public function getCampaignById(string $campaignId): ?Campaign
    {
        return Campaign::find($campaignId);
    }

    public function participate(string $campaignId, int $userId, ?string $walletAddress = null): CampaignParticipation
    {
        $campaign = Campaign::findOrFail($campaignId);
        $user = User::findOrFail($userId);

        $participation = $this->enrollUser($campaign, $user);

        if ($walletAddress) {
            $this->auditService->log([
                'event_type' => 'CAMPAIGN_WALLET_ADDRESS',
                'subject_type' => CampaignParticipation::class,
                'subject_id' => $participation->id,
                'metadata' => [
                    'wallet_address' => $walletAddress,
                    'user_id' => $userId,
                ],
            ]);
        }

        return $participation;
    }

    public function getUserParticipations(int $userId)
    {
        return CampaignParticipation::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function enrollUser(
        Campaign $campaign,
        User $user,
        ?Escrow $escrow = null,
        ?float $benefitAmount = null
    ): CampaignParticipation {
        return DB::transaction(function () use ($campaign, $user, $escrow, $benefitAmount) {
            $campaign = Campaign::where('id', $campaign->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (!$campaign->isActive()) {
                throw new \Exception('Campaign is not active');
            }

            $existingParticipation = CampaignParticipation::where('campaign_id', $campaign->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existingParticipation) {
                throw new \Exception('User already enrolled in campaign');
            }

            if ($this->checkEligibility($campaign, $user) === false) {
                throw new \Exception('User not eligible for campaign');
            }

            $participation = CampaignParticipation::create([
                'tenant_id' => $user->tenant_id,
                'campaign_id' => $campaign->id,
                'user_id' => $user->id,
                'escrow_id' => $escrow?->id,
                'benefit_amount' => $benefitAmount,
                'status' => 'PENDING',
                'idempotency_key' => Str::uuid()->toString(),
            ]);

            $campaign->increment('current_participants');

            if ($benefitAmount) {
                $campaign->increment('budget_used', $benefitAmount);
            }

            $this->auditService->log([
                'event_type' => 'CAMPAIGN_ENROLLMENT',
                'subject_type' => CampaignParticipation::class,
                'subject_id' => $participation->id,
                'metadata' => [
                    'campaign_id' => $campaign->id,
                    'user_id' => $user->id,
                    'benefit_amount' => $benefitAmount,
                ],
            ]);

            return $participation;
        });
    }

    protected function checkEligibility(Campaign $campaign, User $user): bool
    {
        if ($campaign->type === 'FIRST_ESCROW_FREE') {
            $escrowCount = Escrow::where('tenant_id', $user->tenant_id)
                ->where(function ($q) use ($user) {
                    $q->where('buyer_id', $user->id)
                        ->orWhere('seller_id', $user->id);
                })
                ->count();

            return $escrowCount === 0;
        }

        return true;
    }
}
