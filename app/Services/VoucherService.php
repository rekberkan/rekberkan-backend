<?php

namespace App\Services;

use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VoucherService
{
    public function __construct(
        protected BehaviorLogger $behaviorLogger,
        protected AuditService $auditService
    ) {}

    public function getAvailableVouchers(int $tenantId)
    {
        $now = now();

        return Voucher::where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->where(function ($query) use ($now) {
                $query->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $now);
            })
            ->orderByDesc('created_at')
            ->get();
    }

    public function applyVoucher(
        string $voucherCode,
        int $userId,
        int $tenantId,
        string $transactionType,
        float $amount
    ): array {
        $voucher = Voucher::where('tenant_id', $tenantId)
            ->where('code', $voucherCode)
            ->firstOrFail();

        if (!$voucher->isValid()) {
            throw new \Exception('Voucher is not valid');
        }

        if (!$voucher->canBeUsedByUser($userId)) {
            throw new \Exception('Voucher usage limit exceeded for this user');
        }

        $discount = $this->calculateDiscount($voucher, $amount);

        return [
            'voucher_id' => $voucher->id,
            'discount' => $discount,
            'final_amount' => max(0, $amount - $discount),
            'transaction_type' => $transactionType,
        ];
    }

    public function getUserVouchers(int $userId)
    {
        return VoucherRedemption::with('voucher')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function validateVoucher(string $voucherCode, int $tenantId, int $userId, float $amount): array
    {
        $voucher = Voucher::where('tenant_id', $tenantId)
            ->where('code', $voucherCode)
            ->first();

        if (!$voucher) {
            return [
                'valid' => false,
                'message' => 'Voucher not found',
            ];
        }

        if (!$voucher->isValid()) {
            return [
                'valid' => false,
                'message' => 'Voucher is not valid',
            ];
        }

        if (!$voucher->canBeUsedByUser($userId)) {
            return [
                'valid' => false,
                'message' => 'Voucher usage limit exceeded for this user',
            ];
        }

        return [
            'valid' => true,
            'discount' => $this->calculateDiscount($voucher, $amount),
        ];
    }

    public function redeemVoucher(
        string $voucherCode,
        User $user,
        ?int $escrowId = null,
        ?float $orderAmount = null
    ): array {
        $idempotencyKey = Str::uuid()->toString();

        return DB::transaction(function () use ($voucherCode, $user, $escrowId, $orderAmount, $idempotencyKey) {
            $voucher = Voucher::where('tenant_id', $user->tenant_id)
                ->where('code', $voucherCode)
                ->lockForUpdate()
                ->firstOrFail();

            if (!$voucher->isValid()) {
                $this->behaviorLogger->logVoucherRedemptionAttempt(
                    $user->tenant_id,
                    $user->id,
                    $voucherCode,
                    false,
                    'voucher_invalid'
                );

                throw new \Exception('Voucher is not valid');
            }

            if (!$voucher->canBeUsedByUser($user->id)) {
                $this->behaviorLogger->logVoucherRedemptionAttempt(
                    $user->tenant_id,
                    $user->id,
                    $voucherCode,
                    false,
                    'per_user_limit_exceeded'
                );

                throw new \Exception('Voucher usage limit exceeded for this user');
            }

            $discountAmount = $this->calculateDiscount($voucher, $orderAmount);

            $voucher->increment('usage_count');

            $redemption = VoucherRedemption::create([
                'tenant_id' => $user->tenant_id,
                'voucher_id' => $voucher->id,
                'user_id' => $user->id,
                'escrow_id' => $escrowId,
                'discount_amount' => $discountAmount,
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->behaviorLogger->logVoucherRedemptionAttempt(
                $user->tenant_id,
                $user->id,
                $voucherCode,
                true
            );

            $this->auditService->log([
                'event_type' => 'VOUCHER_REDEEMED',
                'subject_type' => VoucherRedemption::class,
                'subject_id' => $redemption->id,
                'metadata' => [
                    'voucher_id' => $voucher->id,
                    'user_id' => $user->id,
                    'discount_amount' => $discountAmount,
                ],
            ]);

            return [
                'redemption_id' => $redemption->id,
                'discount_amount' => $discountAmount,
                'voucher_type' => $voucher->type,
            ];
        });
    }

    protected function calculateDiscount(Voucher $voucher, ?float $orderAmount): float
    {
        if ($voucher->type === 'FIXED_AMOUNT') {
            return (float) $voucher->value;
        }

        if ($voucher->type === 'PERCENTAGE' && $orderAmount) {
            return round(($orderAmount * $voucher->value) / 100, 2);
        }

        return 0;
    }
}
