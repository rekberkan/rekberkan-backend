<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Escrow;
use App\Domain\Escrow\Enums\EscrowStatus;
use App\Application\Services\EscrowService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ProcessAutoReleaseEscrows extends Command
{
    protected $signature = 'escrow:auto-release';
    protected $description = 'Auto-release escrows that have passed SLA deadline';

    public function __construct(
        private EscrowService $escrowService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Processing auto-release escrows...');

        $escrows = Escrow::where('status', EscrowStatus::DELIVERED)
            ->where('sla_auto_release_at', '<=', now())
            ->get();

        $count = 0;
        foreach ($escrows as $escrow) {
            try {
                $this->escrowService->release(
                    escrowId: $escrow->id,
                    userId: 0, // System user
                    idempotencyKey: "auto-release-{$escrow->id}-" . Str::uuid(),
                    isAutoRelease: true
                );

                $count++;
                $this->info("Auto-released escrow {$escrow->id}");
            } catch (\Throwable $e) {
                Log::error('Auto-release failed', [
                    'escrow_id' => $escrow->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Failed to auto-release escrow {$escrow->id}: {$e->getMessage()}");
            }
        }

        $this->info("Auto-released {$count} escrows");
        return Command::SUCCESS;
    }
}
