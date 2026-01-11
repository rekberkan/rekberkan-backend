<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Contracts;

interface LedgerServiceInterface
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
    ): string; // Returns posting_batch_id

    /**
     * Record withdrawal transaction
     */
    public function recordWithdrawal(
        int $tenantId,
        int $walletId,
        int $amount,
        string $withdrawalId,
        string $idempotencyKey
    ): string;

    /**
     * Lock funds (AUTH phase)
     */
    public function lockFunds(
        int $tenantId,
        int $walletId,
        int $amount,
        string $escrowId,
        string $idempotencyKey
    ): string;

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
    ): string;

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
    ): string;
}
