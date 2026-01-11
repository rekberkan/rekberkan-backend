<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Financial\Enums\ProcessingCode;
use App\Domain\Financial\Services\PostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LedgerInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private PostingService $postingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->postingService = app(PostingService::class);
    }

    public function test_double_entry_balance_is_enforced(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Double-entry validation failed');

        // Attempt unbalanced entry (debits != credits)
        $this->postingService->post(
            messageId: 'msg-001',
            tenantId: 'tenant-001',
            processingCode: ProcessingCode::TRANSFER,
            entries: [
                ['account_id' => 'acc-001', 'type' => 'debit', 'amount' => 100000],
                ['account_id' => 'acc-002', 'type' => 'credit', 'amount' => 90000], // Wrong!
            ]
        );
    }

    public function test_negative_balance_is_prevented(): void
    {
        // Setup: Create account with 50,000 balance
        DB::table('account_balances')->insert([
            'account_id' => 'acc-001',
            'balance' => 50000,
            'currency' => 'IDR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('account_balances')->insert([
            'account_id' => 'acc-002',
            'balance' => 0,
            'currency' => 'IDR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Insufficient funds');

        // Attempt to debit 100,000 from account with only 50,000
        $this->postingService->post(
            messageId: 'msg-002',
            tenantId: 'tenant-001',
            processingCode: ProcessingCode::WITHDRAWAL,
            entries: [
                ['account_id' => 'acc-001', 'type' => 'debit', 'amount' => 100000],
                ['account_id' => 'acc-002', 'type' => 'credit', 'amount' => 100000],
            ]
        );
    }

    public function test_idempotency_key_prevents_duplicate_posting(): void
    {
        // Setup accounts
        DB::table('account_balances')->insert([
            ['account_id' => 'acc-001', 'balance' => 100000, 'currency' => 'IDR', 'created_at' => now(), 'updated_at' => now()],
            ['account_id' => 'acc-002', 'balance' => 0, 'currency' => 'IDR', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('financial_messages')->insert([
            'id' => 'msg-003',
            'tenant_id' => 'tenant-001',
            'stan' => '000001',
            'rrn' => '000000000001',
            'processing_code' => ProcessingCode::TRANSFER->value,
            'phase' => 'INITIATED',
            'created_at' => now(),
        ]);

        $idempotencyKey = 'idempotency-key-001';

        // First posting
        $result1 = $this->postingService->post(
            messageId: 'msg-003',
            tenantId: 'tenant-001',
            processingCode: ProcessingCode::TRANSFER,
            entries: [
                ['account_id' => 'acc-001', 'type' => 'debit', 'amount' => 50000],
                ['account_id' => 'acc-002', 'type' => 'credit', 'amount' => 50000],
            ],
            idempotencyKey: $idempotencyKey
        );

        // Second posting with same idempotency key (should return cached result)
        $result2 = $this->postingService->post(
            messageId: 'msg-003',
            tenantId: 'tenant-001',
            processingCode: ProcessingCode::TRANSFER,
            entries: [
                ['account_id' => 'acc-001', 'type' => 'debit', 'amount' => 50000],
                ['account_id' => 'acc-002', 'type' => 'credit', 'amount' => 50000],
            ],
            idempotencyKey: $idempotencyKey
        );

        // Both should return the same batch_id
        $this->assertEquals($result1['batch_id'], $result2['batch_id']);

        // Balance should only be debited once
        $balance = DB::table('account_balances')->where('account_id', 'acc-001')->value('balance');
        $this->assertEquals(50000, $balance); // 100000 - 50000 = 50000 (not 0)
    }

    public function test_ledger_entries_are_immutable(): void
    {
        // Setup and create a ledger entry
        DB::table('account_balances')->insert([
            ['account_id' => 'acc-001', 'balance' => 100000, 'currency' => 'IDR', 'created_at' => now(), 'updated_at' => now()],
            ['account_id' => 'acc-002', 'balance' => 0, 'currency' => 'IDR', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('financial_messages')->insert([
            'id' => 'msg-004',
            'tenant_id' => 'tenant-001',
            'stan' => '000002',
            'rrn' => '000000000002',
            'processing_code' => ProcessingCode::TRANSFER->value,
            'phase' => 'INITIATED',
            'created_at' => now(),
        ]);

        $result = $this->postingService->post(
            messageId: 'msg-004',
            tenantId: 'tenant-001',
            processingCode: ProcessingCode::TRANSFER,
            entries: [
                ['account_id' => 'acc-001', 'type' => 'debit', 'amount' => 25000],
                ['account_id' => 'acc-002', 'type' => 'credit', 'amount' => 25000],
            ]
        );

        // Attempt to update ledger entry (should fail due to trigger)
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Ledger entries are immutable');

        DB::table('ledger_lines')
            ->where('posting_batch_id', $result['batch_id'])
            ->update(['amount' => 99999]);
    }
}
