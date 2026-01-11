<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ValidatesTenantOwnership;
use App\Models\Deposit;
use App\Models\Wallet;
use App\Services\Payment\DepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DepositController extends Controller
{
    use ValidatesTenantOwnership;

    public function __construct(
        private DepositService $depositService
    ) {}

    /**
     * Create a new deposit request
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'min:10000', 'max:100000000'],
            'payment_method' => ['required', 'string', 'in:bank_transfer,va,ewallet,qris'],
            'payment_provider' => ['required', 'string', 'in:midtrans,xendit'],
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

            // Validate user belongs to current tenant
            $this->validateUserTenant($tenantId);

            // Get or create wallet with null check
            $wallet = $user->wallet;
            
            if (!$wallet) {
                // Create wallet if doesn't exist
                $wallet = Wallet::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $user->id,
                    'balance' => 0,
                    'currency' => 'IDR',
                ]);
                
                \Log::info('Wallet auto-created during deposit', [
                    'user_id' => $user->id,
                    'wallet_id' => $wallet->id,
                ]);
            }

            // Validate wallet belongs to tenant
            $this->validateTenantOwnership($wallet, $tenantId);

            $deposit = $this->depositService->createDeposit(
                $user,
                $wallet,
                $request->all(),
                $tenantId
            );

            return response()->json([
                'message' => 'Deposit created successfully',
                'data' => $deposit,
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Deposit creation failed', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id,
                'tenant_id' => $tenantId ?? null,
            ]);

            return response()->json([
                'error' => 'Deposit Creation Failed',
                'message' => 'An error occurred while creating deposit',
            ], 500);
        }
    }

    /**
     * Get user's deposit history with tenant scoping
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $tenantId = $this->getCurrentTenantId();

            $this->validateUserTenant($tenantId);

            // Deposits are automatically scoped by tenant via model trait
            $deposits = Deposit::where('user_id', $user->id)
                ->with(['wallet'])
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            return response()->json([
                'data' => $deposits,
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to fetch deposits', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'error' => 'Failed to fetch deposits',
            ], 500);
        }
    }

    /**
     * Get specific deposit with tenant validation
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user();
            $tenantId = $this->getCurrentTenantId();

            $this->validateUserTenant($tenantId);

            $deposit = Deposit::where('id', $id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Tenant scope is automatically applied via model trait
            // But double-check ownership
            $this->validateTenantOwnership($deposit, $tenantId);

            return response()->json([
                'data' => $deposit->load(['wallet']),
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'error' => 'Deposit not found',
            ], 404);
        }
    }
}
