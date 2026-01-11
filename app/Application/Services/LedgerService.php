<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Ledger\Contracts\LedgerServiceInterface;
use App\Models\PostingBatch;
use App\Models\LedgerLine;
use App\Models\AccountBalance;
use App\Models\Wallet;
use App\Models\PlatformWallet;
use App\Domain\Ledger\Enums\MTIPhase;
use App\Domain\Ledger\Enums\ProcessingCode;
use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\ValueObjects\STAN;
use App\Domain\Ledger\ValueObjects\RRN;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class LedgerService implements LedgerServiceInterface
{
    /**
     * Record deposit transaction
     */
    public function recordDeposit(
        int $tenantId,
        int $walletId,
        int $amount,
        string $depositId,
        string $idempotencyKey
    ): string {
        return DB::transaction(function () use (
            $tenantId,
            $walletId,
            $amount,
            $depositId,
            $idempotencyKey
        ) {
            // Generate STAN and RRN
            $stan = STAN::generate($tenantId);
            $rrn = RRN::generate();

            // Lock wallet for update
            $wallet = Wallet::lockForUpdate()->findOrFail($walletId);

            // Create posting batch
            $batch = PostingBatch::create([
                'tenant_id' => $tenantId,
                'rrn' => $rrn,
                'stan' => $stan,
                'mti_phase' => MTIPhase::PRESENTMENT,
                'idempotency_key' => $idempotencyKey,
                'total_debits' => $amount,
                'total_credits' => $amount,
                'metadata' => [
                    'deposit_id' => $depositId,
                    'processing_code' => ProcessingCode::DEPOSIT->value,
                ],
            ]);

            // Debit: Clearing Suspense (funds coming in)
            $this->createLedgerLine(
                batch: $batch,
                tenantId: $tenantId,
                accountType: AccountType::CLEARING_SUSPENSE,
                accountId: $tenantId, // Platform-level account
                debitAmount: $amount,
                description: "Deposit from payment gateway: {$depositId}"
            );

            // Credit: Customer Available Balance
            $newBalance = $wallet->available_balance + $amount;
            $this->createLedgerLine(
                batch: $batch,
                tenantId: $tenantId,
                accountType: AccountType::CUSTOMER_AVAILABLE,
                accountId: $walletId,
                creditAmount: $amount,
                balanceAfter: $newBalance,
                description: "Deposit credited to wallet: {$walletId}"
            );

            // Update wallet balance
            $wallet->update(['available_balance' => $newBalance]);

            // Update account balance snapshot
            $this->updateAccountBalance(
                tenantId: $tenantId,
                accountType: AccountType::CUSTOMER_AVAILABLE,
                accountId: $walletId,
                newBalance: $newBalance,
                batchId: $batch->id
            );

            Log::info('Deposit recorded in ledger', [
                'batch_id' => $batch->id,
                'wallet_id' => $walletId,
                'amount' => $amount,
            ]);

            return $batch->id;
        });
    }

    /**
     * Record withdrawal transaction
     */
    public function recordWithdrawal(
        int $tenantId,
        int $walletId,
        int $amount,
        string $withdrawalId,
        string $idempotencyKey
    ): string {
        return DB::transaction(function () use (
            $tenantId,
            $walletId,
            $amount,
            $withdrawalId,
            $idempotencyKey
        ) {
            $stan = STAN::generate($tenantId);
            $rrn = RRN::generate();

            $wallet = Wallet::lockForUpdate()->findOrFail($walletId);

            // Validate sufficient balance
            if ($wallet->available_balance < $amount) {
                throw new \Exception('Insufficient balance for withdrawal');
            }

            $batch = PostingBatch::create([
                'tenant_id' => $tenantId,
                'rrn' => $rrn,
                'stan' => $stan,
                'mti_phase' => MTIPhase::PRESENTMENT,
                'idempotency_key' => $idempotencyKey,
                'total_debits' => $amount,
                'total_credits' => $amount,
                'metadata' => [
                    'withdrawal_id' => $withdrawalId,
                    'processing_code' => ProcessingCode::WITHDRAW->value,
                ],
            ]);

            // Debit: Customer Available Balance
            $newBalance = $wallet->available_balance - $amount;
            $this->createLedgerLine(
                batch: $batch,
                tenantId: $tenantId,
                accountType: AccountType::CUSTOMER_AVAILABLE,
                accountId: $walletId,
                debitAmount: $amount,
                balanceAfter: $newBalance,
                description: "Withdrawal from wallet: {$walletId}"
            );

            // Credit: Clearing Suspense (funds going out)
            $this->createLedgerLine(
                batch: $batch,
                tenantId: $tenantId,
                accountType: AccountType::CLEARING_SUSPENSE,
                accountId: $tenantId,
                creditAmount: $amount,
                description: "Withdrawal to payment gateway: {$withdrawalId}"
            );

            // Update wallet
            $wallet->update(['available_balance' => $newBalance]);

            $this->updateAccountBalance(
                tenantId: $tenantId,
                accountType: AccountType::CUSTOMER_AVAILABLE,
                accountId: $walletId,
                newBalance: $newBalance,
                batchId: $batch->id
            );

            Log::info('Withdrawal recorded in ledger', [
                'batch_id' => $batch->id,
                'wallet_id' => $walletId,
                'amount' => $amount,
            ]);

            return $batch->id;
        });
    }

    /**
     * Lock funds (AUTH phase)
     */
    public function lockFunds(
        int $tenantId,
        int $walletId,
        int $amount,
        string $escrowId,
        string $idempotencyKey
    ): string {
        return DB::transaction(function () use (
            $tenantId,
            $walletId,
            $amount,
            $escrowId,
            $idempotencyKey
        ) {
            $stan = STAN::generate($tenantId);
            $rrn = RRN::generate();

            $wallet = Wallet::lockForUpdate()->findOrFail($walletId);

            if ($wallet->available_balance < $amount) {
                throw new \Exception('Insufficient available balance to lock funds');
            }

            $batch = PostingBatch::create([
                'tenant_id' => $tenantId,
                'rrn' => $rrn,
                'stan' => $stan,
                'mti_phase' => MTIPhase::AUTH,
                'idempotency_key' => $idempotencyKey,
                'total_debits' => $amount,
                'total_credits' => $amount,
                'metadata' => [
                    'escrow_id' => $escrowId,
                    'processing_code' => ProcessingCode::ESCROW_LOCK->value,
                ],
            ]);

            // Debit: Customer Locked (increase locked balance)
            $newLockedBalance = $wallet->locked_balance + $amount;
            $this->createLedgerLine(
                batch: $batch,
                tenantId: $tenantId,
                accountType: AccountType::CUSTOMER_LOCKED,
                accountId: $walletId,
                debitAmount: $amount,
                balanceAfter: $newLockedBalance,
                description: "Lock funds for escrow: {$escrowId}"
            );

            // Credit: Customer Available (decrease available)
            $newAvailableBalance = $wallet->available_balance - $amount;
            $this->createLedgerLine(
                batch: $batch,
                tenantId: $tenantId,
                accountType: AccountType::CUSTOMER_AVAILABLE,
                accountId: $walletId,
                creditAmount: $amount,
                balanceAfter: $newAvailableBalance,
                description: "Funds locked for escrow: {$escrowId}"
            );

            // Update wallet
            $wallet->update([
                'available_balance' => $newAvailableBalance,
                'locked_balance' => $newLockedBalance,
            ]);

            $this->updateAccountBalance($tenantId, AccountType::CUSTOMER_AVAILABLE, $walletId, $newAvailableBalance, $batch->id);
            $this->updateAccountBalance($tenantId, AccountType::CUSTOMER_LOCKED, $walletId, $newLockedBalance, $batch->id);

            Log::info('Funds locked in ledger (AUTH)', [
                'batch_id' => $batch->id,
                'escrow_id' => $escrowId,
                'amount' => $amount,
            ]);

            return $batch->id;
        });
    }

    /**
     * Release funds (PRESENTMENT phase)
     */
    public function releaseFunds(
        int $tenantId,
        int $sourceWalletId,
        int $destinationWalletId,
        int $amount,
        int $feeAmount,
        string $escrowId,
        string $idempotencyKey,
        ?string $authBatchId = null
    ): string {
        return DB::transaction(function () use (
            $tenantId,
            $sourceWalletId,
            $destinationWalletId,
            $amount,
            $feeAmount,
            $escrowId,
            $idempotencyKey,
            $authBatchId
        ) {
            $stan = STAN::generate($tenantId);
            $rrn = RRN::generate();

            $sourceWallet = Wallet::lockForUpdate()->findOrFail($sourceWalletId);
            $destWallet = Wallet::lockForUpdate()->findOrFail($destinationWalletId);
            $platformWallet = PlatformWallet::where('tenant_id', $tenantId)->lockForUpdate()->firstOrFail();

            $netAmount = $amount - $feeAmount;

            $batch = PostingBatch::create([
                'tenant_id' => $tenantId,
                'rrn' => $rrn,
                'stan' => $stan,
                'mti_phase' => MTIPhase::PRESENTMENT,
                'idempotency_key' => $idempotencyKey,
                'total_debits' => $amount,
                'total_credits' => $amount,
                'metadata' => [
                    'escrow_id' => $escrowId,
                    'auth_batch_id' => $authBatchId,
                    'processing_code' => ProcessingCode::ESCROW_RELEASE->value,
                ],
            ]);

            // 1. Debit: Source Customer Locked (unlock buyer's funds)
            $newSourceLocked = $sourceWallet->locked_balance - $amount;
            $this->createLedgerLine($batch, $tenantId, AccountType::CUSTOMER_LOCKED, $sourceWalletId, $amount, 0, $newSourceLocked, "Release locked funds for escrow: {$escrowId}");

            // 2. Credit: Destination Customer Available (net to seller)
            $newDestAvailable = $destWallet->available_balance + $netAmount;
            $this->createLedgerLine($batch, $tenantId, AccountType::CUSTOMER_AVAILABLE, $destinationWalletId, 0, $netAmount, $newDestAvailable, "Escrow release to seller: {$escrowId}");

            // 3. Credit: Platform Fees Revenue
            $newPlatformBalance = $platformWallet->available_balance + $feeAmount;
            $this->createLedgerLine($batch, $tenantId, AccountType::FEES_REVENUE, $platformWallet->id, 0, $feeAmount, $newPlatformBalance, "Platform fee from escrow: {$escrowId}");

            // Update wallets
            $sourceWallet->update(['locked_balance' => $newSourceLocked]);
            $destWallet->update(['available_balance' => $newDestAvailable]);
            $platformWallet->update([
                'available_balance' => $newPlatformBalance,
                'total_fees_collected' => $platformWallet->total_fees_collected + $feeAmount,
            ]);

            $this->updateAccountBalance($tenantId, AccountType::CUSTOMER_LOCKED, $sourceWalletId, $newSourceLocked, $batch->id);
            $this->updateAccountBalance($tenantId, AccountType::CUSTOMER_AVAILABLE, $destinationWalletId, $newDestAvailable, $batch->id);

            Log::info('Funds released in ledger (PRESENTMENT)', [
                'batch_id' => $batch->id,
                'escrow_id' => $escrowId,
                'amount' => $amount,
                'fee' => $feeAmount,
            ]);

            return $batch->id;
        });
    }

    /**
     * Refund funds (REVERSAL phase)
     */
    public function refundFunds(
        int $tenantId,
        int $walletId,
        int $amount,
        string $escrowId,
        string $idempotencyKey,
        ?string $authBatchId = null
    ): string {
        return DB::transaction(function () use (
            $tenantId,
            $walletId,
            $amount,
            $escrowId,
            $idempotencyKey,
            $authBatchId
        ) {
            $stan = STAN::generate($tenantId);
            $rrn = RRN::generate();

            $wallet = Wallet::lockForUpdate()->findOrFail($walletId);

            $batch = PostingBatch::create([
                'tenant_id' => $tenantId,
                'rrn' => $rrn,
                'stan' => $stan,
                'mti_phase' => MTIPhase::REVERSAL,
                'idempotency_key' => $idempotencyKey,
                'total_debits' => $amount,
                'total_credits' => $amount,
                'metadata' => [
                    'escrow_id' => $escrowId,
                    'auth_batch_id' => $authBatchId,
                    'processing_code' => ProcessingCode::ESCROW_REFUND->value,
                ],
            ]);

            // Debit: Customer Locked (unlock)
            $newLockedBalance = $wallet->locked_balance - $amount;
            $this->createLedgerLine($batch, $tenantId, AccountType::CUSTOMER_LOCKED, $walletId, $amount, 0, $newLockedBalance, "Unlock funds for refund: {$escrowId}");

            // Credit: Customer Available (return to buyer)
            $newAvailableBalance = $wallet->available_balance + $amount;
            $this->createLedgerLine($batch, $tenantId, AccountType::CUSTOMER_AVAILABLE, $walletId, 0, $amount, $newAvailableBalance, "Refund to buyer: {$escrowId}");

            // Update wallet
            $wallet->update([
                'available_balance' => $newAvailableBalance,
                'locked_balance' => $newLockedBalance,
            ]);

            $this->updateAccountBalance($tenantId, AccountType::CUSTOMER_AVAILABLE, $walletId, $newAvailableBalance, $batch->id);
            $this->updateAccountBalance($tenantId, AccountType::CUSTOMER_LOCKED, $walletId, $newLockedBalance, $batch->id);

            Log::info('Funds refunded in ledger (REVERSAL)', [
                'batch_id' => $batch->id,
                'escrow_id' => $escrowId,
                'amount' => $amount,
            ]);

            return $batch->id;
        });
    }

    /**
     * Create ledger line helper
     */
    private function createLedgerLine(
        PostingBatch $batch,
        int $tenantId,
        AccountType $accountType,
        int $accountId,
        int $debitAmount = 0,
        int $creditAmount = 0,
        ?int $balanceAfter = null,
        ?string $description = null
    ): void {
        LedgerLine::create([
            'posting_batch_id' => $batch->id,
            'tenant_id' => $tenantId,
            'account_type' => $accountType,
            'account_id' => $accountId,
            'debit_amount' => $debitAmount,
            'credit_amount' => $creditAmount,
            'balance_after' => $balanceAfter ?? 0,
            'description' => $description,
        ]);
    }

    /**
     * Update account balance snapshot
     */
    private function updateAccountBalance(
        int $tenantId,
        AccountType $accountType,
        int $accountId,
        int $newBalance,
        string $batchId
    ): void {
        AccountBalance::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'account_type' => $accountType,
                'account_id' => $accountId,
            ],
            [
                'balance' => $newBalance,
                'last_posting_batch_id' => $batchId,
            ]
        );
    }
}
