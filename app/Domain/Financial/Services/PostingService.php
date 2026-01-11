<?php

declare(strict_types=1);

namespace App\Domain\Financial\Services;

use App\Domain\Financial\Enums\MessagePhase;
use App\Domain\Financial\Enums\ProcessingCode;
use App\Domain\Financial\Enums\ResponseCode;
use App\Domain\Financial\ValueObjects\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PostingService
{
    /**
     * Execute atomic double-entry posting.
     * 
     * Guarantees:
     * - Atomicity: All-or-nothing transaction
     * - Idempotency: Safe to retry with same idempotency key
     * - Double-entry: Sum of debits = Sum of credits
     * - No negative balances: CHECK constraint enforced
     * - Deterministic locking: Accounts locked in consistent order
     */
    public function post(
        string $messageId,
        string $tenantId,
        ProcessingCode $processingCode,
        array $entries,
        ?string $idempotencyKey = null
    ): array {
        return DB::transaction(function () use ($messageId, $tenantId, $processingCode, $entries, $idempotencyKey) {
            // Check idempotency
            if ($idempotencyKey) {
                $existing = $this->checkIdempotency($idempotencyKey);
                if ($existing) {
                    return $existing; // Return cached result
                }
            }

            // Validate double-entry balance
            $this->validateDoubleEntry($entries);

            // Lock accounts in deterministic order (prevent deadlocks)
            $accountIds = $this->extractAccountIds($entries);
            $this->lockAccounts($accountIds);

            // Validate sufficient balances
            foreach ($entries as $entry) {
                if ($entry['type'] === 'debit') {
                    $this->validateSufficientBalance(
                        $entry['account_id'],
                        Money::fromMinorUnits($entry['amount'])
                    );
                }
            }

            // Create posting batch
            $batchId = Str::uuid()->toString();
            DB::table('posting_batches')->insert([
                'id' => $batchId,
                'financial_message_id' => $messageId,
                'processing_code' => $processingCode->value,
                'total_debits' => $this->sumByType($entries, 'debit'),
                'total_credits' => $this->sumByType($entries, 'credit'),
                'entry_count' => count($entries),
                'posted_at' => now(),
            ]);

            // Create ledger entries
            foreach ($entries as $entry) {
                $this->createLedgerEntry($batchId, $entry);
            }

            // Update account balances
            foreach ($entries as $entry) {
                $this->updateAccountBalance($entry);
            }

            // Update message phase
            DB::table('financial_messages')
                ->where('id', $messageId)
                ->update([
                    'phase' => MessagePhase::POSTED->value,
                    'response_code' => ResponseCode::APPROVED->value,
                ]);

            // Store idempotency result
            if ($idempotencyKey) {
                $this->storeIdempotencyResult($idempotencyKey, [
                    'batch_id' => $batchId,
                    'response_code' => ResponseCode::APPROVED->value,
                ]);
            }

            return [
                'batch_id' => $batchId,
                'response_code' => ResponseCode::APPROVED->value,
                'message' => 'Transaction posted successfully',
            ];
        });
    }

    /**
     * Validate double-entry balance (sum of debits = sum of credits).
     */
    private function validateDoubleEntry(array $entries): void
    {
        $totalDebits = $this->sumByType($entries, 'debit');
        $totalCredits = $this->sumByType($entries, 'credit');

        if ($totalDebits !== $totalCredits) {
            throw new \Exception(
                "Double-entry validation failed: debits ({$totalDebits}) != credits ({$totalCredits})"
            );
        }
    }

    /**
     * Lock accounts in deterministic order (alphabetically by ID).
     */
    private function lockAccounts(array $accountIds): void
    {
        sort($accountIds); // Deterministic ordering

        foreach ($accountIds as $accountId) {
            DB::select(
                'SELECT id FROM account_balances WHERE account_id = ? FOR UPDATE',
                [$accountId]
            );
        }
    }

    /**
     * Validate sufficient balance for debit operations.
     */
    private function validateSufficientBalance(string $accountId, Money $amount): void
    {
        $balance = DB::table('account_balances')
            ->where('account_id', $accountId)
            ->value('balance');

        if (!$balance || $balance < $amount->getMinorUnits()) {
            throw new \Exception('Insufficient funds');
        }
    }

    /**
     * Create ledger entry.
     */
    private function createLedgerEntry(string $batchId, array $entry): void
    {
        DB::table('ledger_lines')->insert([
            'id' => Str::uuid()->toString(),
            'posting_batch_id' => $batchId,
            'account_id' => $entry['account_id'],
            'amount' => $entry['amount'],
            'direction' => $entry['type'], // 'debit' or 'credit'
            'description' => $entry['description'] ?? null,
            'metadata' => isset($entry['metadata']) ? json_encode($entry['metadata']) : null,
            'created_at' => now(),
        ]);
    }

    /**
     * Update account balance.
     */
    private function updateAccountBalance(array $entry): void
    {
        $modifier = $entry['type'] === 'credit' ? '+' : '-';

        DB::statement(
            "UPDATE account_balances 
             SET balance = balance {$modifier} ?, 
                 updated_at = NOW() 
             WHERE account_id = ?",
            [$entry['amount'], $entry['account_id']]
        );
    }

    /**
     * Extract unique account IDs from entries.
     */
    private function extractAccountIds(array $entries): array
    {
        return array_unique(array_column($entries, 'account_id'));
    }

    /**
     * Sum amounts by entry type.
     */
    private function sumByType(array $entries, string $type): int
    {
        return array_sum(
            array_map(
                fn ($entry) => $entry['amount'],
                array_filter($entries, fn ($entry) => $entry['type'] === $type)
            )
        );
    }

    /**
     * Check idempotency key.
     */
    private function checkIdempotency(string $key): ?array
    {
        $record = DB::table('idempotency_keys')
            ->where('key', $key)
            ->where('expires_at', '>', now())
            ->first();

        return $record ? json_decode($record->response, true) : null;
    }

    /**
     * Store idempotency result.
     */
    private function storeIdempotencyResult(string $key, array $result): void
    {
        DB::table('idempotency_keys')->insert([
            'id' => Str::uuid()->toString(),
            'key' => $key,
            'response' => json_encode($result),
            'expires_at' => now()->addHours(24),
            'created_at' => now(),
        ]);
    }
}
