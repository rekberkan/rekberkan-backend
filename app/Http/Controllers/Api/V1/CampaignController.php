<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CampaignService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CampaignController extends Controller
{
    public function __construct(
        private CampaignService $campaignService
    ) {}

    /**
     * List active campaigns.
     * 
     * NEW CONTROLLER: Expose CampaignService yang sudah ada.
     */
    public function index(Request $request)
    {
        try {
            $tenantId = $this->resolveTenantId($request);
            $campaigns = $this->campaignService->getActiveCampaigns(
                $tenantId
            );

            return response()->json([
                'success' => true,
                'data' => $campaigns,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch campaigns', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch campaigns',
            ], 500);
        }
    }

    /**
     * Get campaign details.
     */
    public function show(Request $request, string $id)
    {
        try {
            $campaign = $this->campaignService->getCampaignById($id);

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
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch campaign',
            ], 500);
        }
    }

    /**
     * Participate in campaign (claim airdrop/reward).
     */
    public function participate(Request $request, string $id)
    {
        $validated = $request->validate([
            'wallet_address' => 'nullable|string',
        ]);

        try {
            $userId = $request->user()->id;
            $result = $this->campaignService->participate(
                $id,
                $userId,
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
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get user's campaign participations.
     */
    public function myParticipations(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $participations = $this->campaignService->getUserParticipations($userId);

            return response()->json([
                'success' => true,
                'data' => $participations,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch participations',
            ], 500);
        }
    }

    private function resolveTenantId(Request $request): int
    {
        $tenantId = $request->attributes->get('tenant_id') ?? $request->user()?->tenant_id;

        if (!$tenantId || !is_numeric($tenantId)) {
            abort(400, 'Tenant context is required');
        }

        $tenantId = (int) $tenantId;

        if ($request->user()?->tenant_id && (int) $request->user()->tenant_id !== $tenantId) {
            abort(403, 'Access denied to this tenant');
        }

        return $tenantId;
    }
}
