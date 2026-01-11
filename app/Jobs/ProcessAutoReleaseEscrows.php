<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Escrow\Services\EscrowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessAutoReleaseEscrows implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct() {}

    /**
     * Auto-release escrows after delivery confirmation period.
     */
    public function handle(EscrowService $escrowService): void
    {
        Log::info('Processing auto-release escrows');

        $escrows = DB::table('escrows')
            ->where('status', 'delivered')
            ->where('auto_release_at', '<=', now())
            ->whereNull('deleted_at')
            ->get();

        foreach ($escrows as $escrow) {
            try {
                $idempotencyKey = "auto-release-{$escrow->id}-" . now()->timestamp;

                $escrowService->release(
                    escrowId: $escrow->id,
                    userId: 'system', // System-initiated
                    idempotencyKey: $idempotencyKey
                );

                Log::info("Auto-released escrow: {$escrow->id}");
            } catch (\Exception $e) {
                Log::error("Failed to auto-release escrow {$escrow->id}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        Log::info('Auto-release processing completed', ['count' => $escrows->count()]);
    }
}
