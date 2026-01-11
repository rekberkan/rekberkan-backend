<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Escrow\CreateEscrowRequest;
use App\Http\Requests\Escrow\FundEscrowRequest;
use App\Http\Requests\Escrow\DisputeEscrowRequest;
use App\Http\Requests\Escrow\MarkDeliveredRequest;
use App\Http\Requests\Escrow\ReleaseEscrowRequest;
use App\Http\Requests\Escrow\RefundEscrowRequest;
use App\Application\Services\EscrowService;
use App\Http\Resources\EscrowResource;
use App\Models\Escrow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class EscrowController extends Controller
{
    public function __construct(
        private EscrowService $escrowService
    ) {}

    /**
     * Create escrow
     */
    public function store(CreateEscrowRequest $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->getTenantId($request);

        // Validate tenant ownership (FIX: Bug #6)
        $this->validateTenantOwnership($user, $tenantId);

        $escrow = $this->escrowService->create(
            tenantId: $tenantId,
            buyerId: $user->id,
            sellerId: $request->seller_id,
            amount: $request->amount,
            title: $request->title,
            description: $request->description ?? '',
            idempotencyKey: $this->getIdempotencyKey($request, 'escrow-create')
        );

        return response()->json([
            'data' => new EscrowResource($escrow),
        ], 201);
    }

    /**
     * Get escrow by ID
     */
    public function show(string $id): JsonResponse
    {
        $userId = request()->user()->id;
        $tenantId = $this->getTenantId(request());

        $escrow = Escrow::with(['buyer', 'seller', 'timeline'])
            ->where('id', $id)
            ->where('tenant_id', $tenantId)  // Tenant isolation
            ->where(function ($query) use ($userId) {
                $query->where('buyer_id', $userId)
                    ->orWhere('seller_id', $userId);
            })
            ->firstOrFail();

        return response()->json([
            'data' => new EscrowResource($escrow),
        ]);
    }

    /**
     * List user escrows with configurable pagination (FIX: Bug #11)
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $tenantId = $this->getTenantId($request);
        $perPage = min((int) $request->input('per_page', 20), 100); // Max 100

        $escrows = Escrow::where('tenant_id', $tenantId)
            ->where(function ($query) use ($userId) {
                $query->where('buyer_id', $userId)
                      ->orWhere('seller_id', $userId);
            })
            ->with(['buyer', 'seller'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'data' => EscrowResource::collection($escrows),
            'meta' => [
                'current_page' => $escrows->currentPage(),
                'last_page' => $escrows->lastPage(),
                'per_page' => $escrows->perPage(),
                'total' => $escrows->total(),
            ],
        ]);
    }

    /**
     * Fund escrow
     */
    public function fund(string $id, FundEscrowRequest $request): JsonResponse
    {
        $escrow = $this->escrowService->fund(
            escrowId: $id,
            userId: $request->user()->id,
            idempotencyKey: $this->getIdempotencyKey($request, "escrow-fund-{$id}")
        );

        return response()->json([
            'data' => new EscrowResource($escrow),
        ]);
    }

    /**
     * Mark as delivered (FIX: Bug #9 - added validation)
     */
    public function markDelivered(string $id, MarkDeliveredRequest $request): JsonResponse
    {
        $escrow = $this->escrowService->markDelivered(
            escrowId: $id,
            userId: $request->user()->id
        );

        return response()->json([
            'data' => new EscrowResource($escrow),
        ]);
    }

    /**
     * Release funds (FIX: Bug #9 - added validation)
     */
    public function release(string $id, ReleaseEscrowRequest $request): JsonResponse
    {
        $escrow = $this->escrowService->release(
            escrowId: $id,
            userId: $request->user()->id,
            idempotencyKey: $this->getIdempotencyKey($request, "escrow-release-{$id}")
        );

        return response()->json([
            'data' => new EscrowResource($escrow),
        ]);
    }

    /**
     * Refund escrow (FIX: Bug #9 - added validation)
     */
    public function refund(string $id, RefundEscrowRequest $request): JsonResponse
    {
        $escrow = $this->escrowService->refund(
            escrowId: $id,
            userId: $request->user()->id,
            idempotencyKey: $this->getIdempotencyKey($request, "escrow-refund-{$id}")
        );

        return response()->json([
            'data' => new EscrowResource($escrow),
        ]);
    }

    /**
     * Open dispute
     */
    public function dispute(string $id, DisputeEscrowRequest $request): JsonResponse
    {
        $escrow = $this->escrowService->dispute(
            escrowId: $id,
            userId: $request->user()->id,
            reason: $request->reason
        );

        return response()->json([
            'data' => new EscrowResource($escrow),
        ]);
    }

    /**
     * Get tenant ID from request header
     */
    private function getTenantId(Request $request): int
    {
        $tenantId = $request->header('X-Tenant-ID');
        
        if (!$tenantId || !is_numeric($tenantId)) {
            abort(400, 'Invalid or missing X-Tenant-ID header');
        }

        return (int) $tenantId;
    }

    /**
     * Validate user has access to tenant (FIX: Bug #6)
     */
    private function validateTenantOwnership($user, int $tenantId): void
    {
        // Check if user belongs to this tenant
        if (!$user->tenants()->where('tenant_id', $tenantId)->exists()) {
            abort(403, 'Access denied to this tenant');
        }
    }

    /**
     * Generate secure idempotency key (FIX: Bug #3)
     */
    private function getIdempotencyKey(Request $request, string $prefix): string
    {
        // Use header if provided, otherwise generate ULID
        $headerKey = $request->header('X-Idempotency-Key');
        
        if ($headerKey && strlen($headerKey) >= 16) {
            return $headerKey;
        }

        // Generate cryptographically secure ULID
        return $prefix . '-' . (string) Str::ulid();
    }
}
