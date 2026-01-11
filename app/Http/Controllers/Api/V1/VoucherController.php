<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\VoucherService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VoucherController extends Controller
{
    public function __construct(
        private VoucherService $voucherService
    ) {}

    /**
     * List available vouchers for user.
     * 
     * NEW CONTROLLER: Expose VoucherService yang sudah ada.
     */
    public function index(Request $request)
    {
        try {
            $tenantId = $request->attributes->get('tenant_id');
            $vouchers = $this->voucherService->getAvailableVouchers($tenantId);

            return response()->json([
                'success' => true,
                'data' => $vouchers,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch vouchers',
            ], 500);
        }
    }

    /**
     * Apply voucher code to transaction.
     */
    public function apply(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string',
            'transaction_type' => 'required|in:deposit,withdrawal,escrow',
            'amount' => 'required|numeric|min:0',
        ]);

        try {
            $userId = $request->user()->id;
            $tenantId = $request->attributes->get('tenant_id');

            $result = $this->voucherService->applyVoucher(
                $validated['code'],
                $userId,
                $tenantId,
                $validated['transaction_type'],
                $validated['amount']
            );

            return response()->json([
                'success' => true,
                'message' => 'Voucher applied successfully',
                'data' => [
                    'original_amount' => $validated['amount'],
                    'discount' => $result['discount'],
                    'final_amount' => $result['final_amount'],
                    'voucher_id' => $result['voucher_id'],
                ],
            ]);
        } catch (\Exception $e) {
            Log::warning('Voucher application failed', [
                'code' => $validated['code'],
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
     * Get user's claimed vouchers.
     */
    public function myVouchers(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $vouchers = $this->voucherService->getUserVouchers($userId);

            return response()->json([
                'success' => true,
                'data' => $vouchers,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user vouchers',
            ], 500);
        }
    }

    /**
     * Validate voucher code (check without applying).
     */
    public function validate(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string',
            'amount' => 'required|numeric|min:0',
        ]);

        try {
            $userId = $request->user()->id;
            $result = $this->voucherService->validateVoucher(
                $validated['code'],
                $userId,
                $validated['amount']
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'valid' => $result['valid'],
                    'discount' => $result['discount'] ?? 0,
                    'message' => $result['message'] ?? null,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
            ], 400);
        }
    }
}
