<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Models\Campaign;
use App\Models\CampaignParticipation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Carbon\Carbon;

final class CampaignService
{
    /**
     * Get active campaigns
     */
    public function getActiveCampaigns(): Collection
    {
        return Campaign::where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get campaign by ID
     */
    public function getCampaignById(string $id): Campaign
    {
        $campaign = Campaign::find($id);
        
        if (!$campaign) {
            throw new \Exception('Campaign not found');
        }
        
        return $campaign;
    }

    /**
     * Check if user is eligible for campaign
     */
    public function checkEligibility(string $campaignId, int $userId): array
    {
        $campaign = $this->getCampaignById($campaignId);
        
        // Check if campaign is active
        if ($campaign->status !== 'active') {
            return [
                'eligible' => false,
                'reason' => 'Campaign is not active',
            ];
        }
        
        // Check if campaign period is valid
        if (Carbon::parse($campaign->starts_at)->isFuture()) {
            return [
                'eligible' => false,
                'reason' => 'Campaign has not started yet',
            ];
        }
        
        if (Carbon::parse($campaign->ends_at)->isPast()) {
            return [
                'eligible' => false,
                'reason' => 'Campaign has ended',
            ];
        }
        
        // Check if user already participated
        $hasParticipated = CampaignParticipation::where('campaign_id', $campaignId)
            ->where('user_id', $userId)
            ->exists();
            
        if ($hasParticipated && !$campaign->allow_multiple_entries) {
            return [
                'eligible' => false,
                'reason' => 'User has already participated',
            ];
        }
        
        // Check if campaign has reached max participants
        if ($campaign->max_participants) {
            $currentParticipants = CampaignParticipation::where('campaign_id', $campaignId)
                ->distinct('user_id')
                ->count();
                
            if ($currentParticipants >= $campaign->max_participants) {
                return [
                    'eligible' => false,
                    'reason' => 'Campaign has reached maximum participants',
                ];
            }
        }
        
        return [
            'eligible' => true,
            'reason' => null,
        ];
    }

    /**
     * Enroll user in campaign
     */
    public function enrollUser(string $campaignId, int $userId): CampaignParticipation
    {
        return DB::transaction(function () use ($campaignId, $userId) {
            // Check eligibility
            $eligibility = $this->checkEligibility($campaignId, $userId);
            
            if (!$eligibility['eligible']) {
                throw new \Exception($eligibility['reason']);
            }
            
            $campaign = $this->getCampaignById($campaignId);
            
            // Create participation record
            $participation = CampaignParticipation::create([
                'campaign_id' => $campaignId,
                'user_id' => $userId,
                'status' => 'active',
                'enrolled_at' => now(),
            ]);
            
            // Award initial reward if applicable
            if ($campaign->reward_type === 'instant' && $campaign->reward_amount) {
                $this->awardReward($participation);
            }
            
            return $participation;
        });
    }

    /**
     * Participate in campaign (alias for enrollUser for backward compatibility)
     */
    public function participate(string $campaignId, int $userId): CampaignParticipation
    {
        return $this->enrollUser($campaignId, $userId);
    }

    /**
     * Get user participations
     */
    public function getUserParticipations(int $userId): Collection
    {
        return CampaignParticipation::with('campaign')
            ->where('user_id', $userId)
            ->orderBy('enrolled_at', 'desc')
            ->get();
    }

    /**
     * Award reward to participant
     */
    private function awardReward(CampaignParticipation $participation): void
    {
        $campaign = $participation->campaign;
        
        if ($campaign->reward_type === 'points') {
            // Award points
            DB::table('user_points')
                ->where('user_id', $participation->user_id)
                ->increment('points', $campaign->reward_amount);
        } elseif ($campaign->reward_type === 'voucher') {
            // Create voucher for user
            DB::table('user_vouchers')->insert([
                'user_id' => $participation->user_id,
                'voucher_id' => $campaign->reward_voucher_id,
                'campaign_id' => $campaign->id,
                'expires_at' => now()->addDays(30),
                'created_at' => now(),
            ]);
        } elseif ($campaign->reward_type === 'balance') {
            // Credit user wallet
            DB::table('account_balances')
                ->where('user_id', $participation->user_id)
                ->increment('balance', $campaign->reward_amount);
        }
        
        // Mark reward as awarded
        $participation->update([
            'reward_awarded' => true,
            'reward_awarded_at' => now(),
        ]);
    }
}
