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

    /**
     * Get active campaigns with optional tenant filter
     */
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

    /**
     * Get campaign by ID (without tenant filter - use with caution)
     * @deprecated Use getCampaignByIdWithTenant() instead
     */
    public function getCampaignById(string $campaignId): ?Campaign
    {
        return Campaign::find($campaignId);
    }

    /**
     * Get campaign by ID with tenant filter (safe)
     */
    public function getCampaignByIdWithTenant(string $campaignId, int $tenantId): ?Campaign
    {
        return Campaign::where('id', $campaignId)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    /**
     * Participate in campaign (without tenant validation)
     * @deprecated Use participateWithTenant() instead
     */
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

    /**
     * Participate in campaign with tenant enforcement (safe)
     */
    public function participateWithTenant(
        string $campaignId,
        int $userId,
        int $tenantId,
        ?string $walletAddress = null
    ): CampaignParticipation {
        // Validate campaign belongs to tenant
        $campaign = Campaign::where('id', $campaignId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        // Validate user belongs to tenant
        $user = User::where('id', $userId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $participation = $this->enrollUser($campaign, $user);

        if ($walletAddress) {
            $this->auditService->log([
                'event_type' => 'CAMPAIGN_WALLET_ADDRESS',
                'subject_type' => CampaignParticipation::class,
                'subject_id' => $participation->id,
                'metadata' => [
                    'wallet_address' => $walletAddress,
                    'user_id' => $userId,
                    'tenant_id' => $tenantId,
                ],
            ]);
        }

        return $participation;
    }

    /**
     * Get user participations with optional tenant filter
     */
    public function getUserParticipations(int $userId, ?int $tenantId = null)
    {
        $query = CampaignParticipation::where('user_id', $userId);

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->with(['campaign'])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Enroll user in campaign (internal method)
     */
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

            // Check existing participation
            $existingParticipation = CampaignParticipation::where('campaign_id', $campaign->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existingParticipation) {
                throw new \Exception('User already enrolled in campaign');
            }

            // Validate tenant match
            if ($campaign->tenant_id !== $user->tenant_id) {
                throw new \Exception('Campaign and user tenant mismatch');
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
                    'tenant_id' => $user->tenant_id,
                    'benefit_amount' => $benefitAmount,
                ],
            ]);

            return $participation;
        });
    }

    /**
     * Check user eligibility for campaign
     */
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
