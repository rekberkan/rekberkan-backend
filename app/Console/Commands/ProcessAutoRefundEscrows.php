<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Escrow;
use App\Domain\Escrow\Enums\EscrowStatus;
use App\Application\Services\EscrowService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ProcessAutoRefundEscrows extends Command
{
    protected $signature = 'escrow:auto-refund';
    protected $description = 'Auto-refund escrows that have expired';

    public function __construct(
        private EscrowService $escrowService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Processing auto-refund escrows...');

        // Refund expired pending-payment escrows
        $createdExpired = Escrow::where('status', EscrowStatus::PENDING_PAYMENT)
            ->where('sla_auto_refund_at', '<=', now())
            ->get();

        // Refund expired FUNDED escrows that were never delivered
        $fundedExpired = Escrow::where('status', EscrowStatus::FUNDED)
            ->where('sla_auto_refund_at', '<=', now())
            ->whereNull('delivered_at')
            ->get();

        $escrows = $createdExpired->merge($fundedExpired);
        $count = 0;

        foreach ($escrows as $escrow) {
            try {
                // Cancel pending-payment escrows that expired
                if ($escrow->status === EscrowStatus::PENDING_PAYMENT) {
                    $escrow->update([
                        'status' => EscrowStatus::CANCELLED,
                        'cancelled_at' => now(),
                    ]);
                } else {
                    // FUNDED → REFUNDED
                    $this->escrowService->refund(
                        escrowId: $escrow->id,
                        userId: 0, // System user
                        idempotencyKey: "auto-refund-{$escrow->id}-" . Str::uuid(),
                        isAutoRefund: true
                    );
                    $count++;
                }

                $this->info("Processed expired escrow {$escrow->id}");
            } catch (\Throwable $e) {
                Log::error('Auto-refund failed', [
                    'escrow_id' => $escrow->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Failed to process escrow {$escrow->id}: {$e->getMessage()}");
            }
        }

        $this->info("Processed {$escrows->count()} expired escrows ({$count} refunded)");
        return Command::SUCCESS;
    }
}
