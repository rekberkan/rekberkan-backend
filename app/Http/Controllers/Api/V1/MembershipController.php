<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MembershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MembershipController extends Controller
{
    /**
     * Membership management controller.
     */
    public function __construct(
        private MembershipService $membershipService
    ) {}

    /**
     * Get user's current membership.
     */
    public function index(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $membership = $this->membershipService->getUserMembership($userId);

            if (!$membership) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'tier' => 'free',
                        'status' => 'active',
                        'benefits' => \App\Models\Membership::getTierConfig('free')['benefits'],
                    ],
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'membership' => $membership,
                    'benefits' => $membership->getBenefits(),
                    'is_active' => $membership->isActive(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch membership',
            ], 500);
        }
    }

    /**
     * Get available membership tiers.
     */
    public function tiers(Request $request)
    {
        try {
            $tiers = $this->membershipService->getAvailableTiers();

            return response()->json([
                'success' => true,
                'data' => $tiers,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch tiers',
            ], 500);
        }
    }

    /**
     * Subscribe to membership tier.
     */
    public function subscribe(Request $request)
    {
        $validated = $request->validate([
            'tier' => 'required|in:bronze,silver,gold,platinum',
            'payment_method' => 'nullable|in:wallet,bank_transfer,credit_card',
        ]);

        try {
            $userId = $request->user()->id;
            $tenantId = $this->resolveTenantId($request);

            $membership = $this->membershipService->subscribe(
                $userId,
                $tenantId,
                $validated['tier'],
                $validated['payment_method'] ?? 'wallet'
            );

            return response()->json([
                'success' => true,
                'message' => 'Successfully subscribed to membership',
                'data' => $membership,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Membership subscription failed', [
                'user_id' => $request->user()->id,
                'tier' => $validated['tier'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Cancel membership.
     */
    public function cancel(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $this->membershipService->cancel($userId);

            return response()->json([
                'success' => true,
                'message' => 'Membership cancelled. Benefits will remain until expiration date.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get membership benefits usage/statistics.
     */
    public function benefits(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $membership = $this->membershipService->getUserMembership($userId);

            $tier = $membership ? $membership->tier : 'free';
            $benefits = \App\Models\Membership::getTierConfig($tier)['benefits'];

            $tenantId = $this->resolveTenantId($request);
            $usage = $this->membershipService->getUsageStats($userId, $tenantId, $tier, $membership);

            return response()->json([
                'success' => true,
                'data' => [
                    'benefits' => $benefits,
                    'usage' => $usage,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch benefits',
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
