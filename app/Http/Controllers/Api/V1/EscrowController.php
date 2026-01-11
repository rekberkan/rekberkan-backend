<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ValidatesTenantOwnership;
use App\Models\Escrow;
use App\Services\EscrowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EscrowController extends Controller
{
    use ValidatesTenantOwnership;

    public function __construct(
        private EscrowService $escrowService
    ) {}

    /**
     * Create new escrow with tenant enforcement
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'seller_id' => ['required', 'exists:users,id'],
            'amount' => ['required', 'numeric', 'min:10000'],
            'description' => ['required', 'string', 'max:1000'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation Error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = $request->user();
            $tenantId = $this->getCurrentTenantId();

            $this->validateUserTenant($tenantId);

            $escrow = $this->escrowService->create(
                $user,
                $request->validated(),
                $tenantId
            );

            return response()->json([
                'message' => 'Escrow created successfully',
                'data' => $escrow,
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Escrow creation failed', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'error' => 'Escrow Creation Failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get escrow details with tenant validation
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $tenantId = $this->getCurrentTenantId();

            $this->validateUserTenant($tenantId);

            // Use service method with tenant filter
            $escrow = $this->escrowService->findByIdWithTenant($id, $tenantId);

            if (!$escrow) {
                return response()->json([
                    'error' => 'Escrow not found',
                ], 404);
            }

            // Validate user is participant (buyer or seller)
            if ($escrow->buyer_id !== $user->id && $escrow->seller_id !== $user->id) {
                return response()->json([
                    'error' => 'Access denied',
                ], 403);
            }

            return response()->json([
                'data' => $escrow->load(['buyer', 'seller']),
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to fetch escrow', [
                'error' => $e->getMessage(),
                'escrow_id' => $id,
            ]);

            return response()->json([
                'error' => 'Failed to fetch escrow',
            ], 500);
        }
    }

    /**
     * List user's escrows with tenant scoping
     * 
     * SECURITY FIX: Bug #5 - Add tenant_id filtering to prevent cross-tenant access
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $tenantId = $this->getCurrentTenantId();

            $this->validateUserTenant($tenantId);

            // SECURITY FIX: Add tenant_id filter to prevent cross-tenant data leakage
            $escrows = Escrow::where('tenant_id', $tenantId)
                ->where(function ($query) use ($user) {
                    $query->where('buyer_id', $user->id)
                        ->orWhere('seller_id', $user->id);
                })
                ->with(['buyer', 'seller'])
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            return response()->json([
                'data' => $escrows,
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to fetch escrows', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to fetch escrows',
            ], 500);
        }
    }

    /**
     * Release escrow funds
     */
    public function release(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $tenantId = $this->getCurrentTenantId();

            $escrow = $this->escrowService->findByIdWithTenant($id, $tenantId);

            if (!$escrow) {
                return response()->json(['error' => 'Escrow not found'], 404);
            }

            // Only buyer can release
            if ($escrow->buyer_id !== $user->id) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $this->escrowService->release($escrow);

            return response()->json([
                'message' => 'Escrow released successfully',
                'data' => $escrow->fresh(),
            ]);

        } catch (\Exception $e) {
            \Log::error('Escrow release failed', [
                'error' => $e->getMessage(),
                'escrow_id' => $id,
            ]);

            return response()->json([
                'error' => 'Failed to release escrow',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cancel/refund escrow
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $tenantId = $this->getCurrentTenantId();

            $escrow = $this->escrowService->findByIdWithTenant($id, $tenantId);

            if (!$escrow) {
                return response()->json(['error' => 'Escrow not found'], 404);
            }

            // Buyer or seller can request cancellation
            if ($escrow->buyer_id !== $user->id && $escrow->seller_id !== $user->id) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $this->escrowService->cancel($escrow, $user);

            return response()->json([
                'message' => 'Escrow cancellation initiated',
                'data' => $escrow->fresh(),
            ]);

        } catch (\Exception $e) {
            \Log::error('Escrow cancellation failed', [
                'error' => $e->getMessage(),
                'escrow_id' => $id,
            ]);

            return response()->json([
                'error' => 'Failed to cancel escrow',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
