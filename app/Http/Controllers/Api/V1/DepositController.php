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
use Illuminate\Support\Str;

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
        $tenantId = $this->getTenantId($request);

        // Validate tenant ownership (FIX: Bug #6)
        $this->validateTenantOwnership($user, $tenantId);

        $deposit = $this->xenditService->createDeposit(
            tenantId: $tenantId,
            userId: $user->id,
            walletId: $wallet->id,
            amount: $request->amount,
            method: PaymentMethod::from($request->payment_method),
            idempotencyKey: $this->getIdempotencyKey($request, 'deposit')
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
        $tenantId = $this->getTenantId(request());

        $deposit = Deposit::with(['user', 'wallet'])
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('user_id', request()->user()->id)
            ->firstOrFail();

        return response()->json([
            'data' => new DepositResource($deposit),
        ]);
    }

    /**
     * List user deposits with configurable pagination (FIX: Bug #11)
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        $perPage = min((int) $request->input('per_page', 20), 100);

        $deposits = Deposit::where('tenant_id', $tenantId)
            ->where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

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
     * 
     * Note: Signature verification is handled by VerifyXenditWebhook middleware
     * (FIX: Bug #1, #4)
     */
    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->all();
        $signature = $request->header('X-Callback-Token');
        $rawPayload = $request->getContent();

        try {
            // Middleware already verified signature and IP
            $this->xenditService->processDepositWebhook(
                payload: $payload,
                signature: $signature,
                ipAddress: $request->ip(),
                rawPayload: $rawPayload
            );

            return response()->json(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            // Business logic error (e.g., deposit not found)
            \Log::warning('Xendit webhook business error', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Invalid request',
            ], 400);
        } catch (\Throwable $e) {
            // System error (FIX: Bug #14 - better error handling)
            \Log::error('Xendit webhook system error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Internal server error',
            ], 500);
        }
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
        if (!$user->tenants()->where('tenant_id', $tenantId)->exists()) {
            abort(403, 'Access denied to this tenant');
        }
    }

    /**
     * Generate secure idempotency key (FIX: Bug #3)
     */
    private function getIdempotencyKey(Request $request, string $prefix): string
    {
        $headerKey = $request->header('X-Idempotency-Key');
        
        if ($headerKey && strlen($headerKey) >= 16) {
            return $headerKey;
        }

        return $prefix . '-' . (string) Str::ulid();
    }
}
