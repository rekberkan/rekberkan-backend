<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Deposit\CreateDepositRequest;
use App\Application\Services\XenditService;
use App\Http\Resources\DepositResource;
use App\Models\Deposit;
use App\Domain\Payment\Enums\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DepositController extends Controller
{
    public function __construct(
        private XenditService $xenditService
    ) {}

    /**
     * Create deposit
     */
    public function store(CreateDepositRequest $request): JsonResponse
    {
        $user = $request->user();
        $wallet = $user->wallet;

        $deposit = $this->xenditService->createDeposit(
            tenantId: (int) $request->header('X-Tenant-ID'),
            userId: $user->id,
            walletId: $wallet->id,
            amount: $request->amount,
            method: PaymentMethod::from($request->payment_method),
            idempotencyKey: $request->header('X-Idempotency-Key') ?? "deposit-{$user->id}-" . time()
        );

        return response()->json([
            'data' => new DepositResource($deposit),
        ], 201);
    }

    /**
     * Get deposit by ID
     */
    public function show(string $id): JsonResponse
    {
        $deposit = Deposit::with(['user', 'wallet'])
            ->where('id', $id)
            ->where('user_id', request()->user()->id)
            ->firstOrFail();

        return response()->json([
            'data' => new DepositResource($deposit),
        ]);
    }

    /**
     * List user deposits
     */
    public function index(Request $request): JsonResponse
    {
        $deposits = Deposit::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'data' => DepositResource::collection($deposits),
            'meta' => [
                'current_page' => $deposits->currentPage(),
                'last_page' => $deposits->lastPage(),
                'per_page' => $deposits->perPage(),
                'total' => $deposits->total(),
            ],
        ]);
    }

    /**
     * Webhook endpoint for Xendit
     */
    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->all();
        $signature = $request->header('X-Callback-Token');
        $rawPayload = $request->getContent();

        try {
            $this->xenditService->processDepositWebhook(
                payload: $payload,
                signature: $signature,
                ipAddress: $request->ip(),
                rawPayload: $rawPayload
            );

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            \Log::error('Xendit webhook processing failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Webhook processing failed',
            ], 400);
        }
    }
}
