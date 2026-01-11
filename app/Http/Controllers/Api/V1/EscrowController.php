<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Escrow\CreateEscrowRequest;
use App\Http\Requests\Escrow\FundEscrowRequest;
use App\Http\Requests\Escrow\DisputeEscrowRequest;
use App\Application\Services\EscrowService;
use App\Http\Resources\EscrowResource;
use App\Models\Escrow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $escrow = $this->escrowService->create(
            tenantId: (int) $request->header('X-Tenant-ID'),
            buyerId: $user->id,
            sellerId: $request->seller_id,
            amount: $request->amount,
            title: $request->title,
            description: $request->description ?? '',
            idempotencyKey: $request->header('X-Idempotency-Key') ?? "escrow-{$user->id}-" . time()
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
        $escrow = Escrow::with(['buyer', 'seller', 'timeline'])->findOrFail($id);

        return response()->json([
            'data' => new EscrowResource($escrow),
        ]);
    }

    /**
     * List user escrows
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $escrows = Escrow::where(function ($query) use ($userId) {
            $query->where('buyer_id', $userId)
                  ->orWhere('seller_id', $userId);
        })
        ->with(['buyer', 'seller'])
        ->orderBy('created_at', 'desc')
        ->paginate(20);

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
            idempotencyKey: $request->header('X-Idempotency-Key') ?? "fund-{$id}-" . time()
        );

        return response()->json([
            'data' => new EscrowResource($escrow),
        ]);
    }

    /**
     * Mark as delivered
     */
    public function markDelivered(string $id, Request $request): JsonResponse
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
     * Release funds
     */
    public function release(string $id, Request $request): JsonResponse
    {
        $escrow = $this->escrowService->release(
            escrowId: $id,
            userId: $request->user()->id,
            idempotencyKey: $request->header('X-Idempotency-Key') ?? "release-{$id}-" . time()
        );

        return response()->json([
            'data' => new EscrowResource($escrow),
        ]);
    }

    /**
     * Refund escrow
     */
    public function refund(string $id, Request $request): JsonResponse
    {
        $escrow = $this->escrowService->refund(
            escrowId: $id,
            userId: $request->user()->id,
            idempotencyKey: $request->header('X-Idempotency-Key') ?? "refund-{$id}-" . time()
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
}
