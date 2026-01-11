<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ValidatesTenantOwnership;
use App\Services\CampaignService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CampaignController extends Controller
{
    use ValidatesTenantOwnership;

    public function __construct(
        private CampaignService $campaignService
    ) {}

    /**
     * List active campaigns with tenant scoping
     */
    public function index(Request $request)
    {
        try {
            $tenantId = $this->getCurrentTenantId();
            $this->validateUserTenant($tenantId);

            $campaigns = $this->campaignService->getActiveCampaigns(
                $tenantId
            );

            return response()->json([
                'success' => true,
                'data' => $campaigns,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch campaigns', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch campaigns',
            ], 500);
        }
    }

    /**
     * Get campaign details with tenant enforcement
     */
    public function show(Request $request, string $id)
    {
        try {
            $tenantId = $this->getCurrentTenantId();
            $this->validateUserTenant($tenantId);

            // Service now validates tenant ownership
            $campaign = $this->campaignService->getCampaignByIdWithTenant($id, $tenantId);

            if (!$campaign) {
                return response()->json([
                    'success' => false,
                    'message' => 'Campaign not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $campaign,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch campaign', [
                'campaign_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch campaign',
            ], 500);
        }
    }

    /**
     * Participate in campaign with tenant enforcement
     */
    public function participate(Request $request, string $id)
    {
        $validated = $request->validate([
            'wallet_address' => 'nullable|string',
        ]);

        try {
            $userId = $request->user()->id;
            $tenantId = $this->getCurrentTenantId();
            $this->validateUserTenant($tenantId);

            // Service validates campaign belongs to tenant
            $result = $this->campaignService->participateWithTenant(
                $id,
                $userId,
                $tenantId,
                $validated['wallet_address'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Successfully participated in campaign',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Campaign participation failed', [
                'campaign_id' => $id,
                'user_id' => $request->user()->id,
                'tenant_id' => $tenantId ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get user's campaign participations with tenant scoping
     */
    public function myParticipations(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $tenantId = $this->getCurrentTenantId();
            $this->validateUserTenant($tenantId);

            $participations = $this->campaignService->getUserParticipations($userId, $tenantId);

            return response()->json([
                'success' => true,
                'data' => $participations,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch participations', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch participations',
            ], 500);
        }
    }
}
