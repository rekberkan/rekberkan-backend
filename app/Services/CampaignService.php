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
