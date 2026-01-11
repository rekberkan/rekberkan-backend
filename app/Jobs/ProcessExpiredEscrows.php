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

class ProcessExpiredEscrows implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct() {}

    /**
     * Auto-refund expired escrows that were never funded.
     */
    public function handle(EscrowService $escrowService): void
    {
        Log::info('Processing expired escrows');

        // Cancel unfunded escrows past expiration
        $pendingEscrows = DB::table('escrows')
            ->where('status', 'pending_payment')
            ->where('expires_at', '<', now())
            ->whereNull('deleted_at')
            ->get();

        foreach ($pendingEscrows as $escrow) {
            try {
                DB::table('escrows')->where('id', $escrow->id)->update([
                    'status' => 'cancelled',
                    'updated_at' => now(),
                ]);

                DB::table('escrow_timelines')->insert([
                    'id' => \Illuminate\Support\Str::uuid()->toString(),
                    'escrow_id' => $escrow->id,
                    'status' => 'cancelled',
                    'note' => 'Automatically cancelled due to expiration',
                    'created_at' => now(),
                ]);

                Log::info("Cancelled expired escrow: {$escrow->id}");
            } catch (\Exception $e) {
                Log::error("Failed to cancel expired escrow {$escrow->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Refund funded escrows past expiration (if still in funded state)
        $fundedEscrows = DB::table('escrows')
            ->where('status', 'funded')
            ->where('expires_at', '<', now())
            ->whereNull('deleted_at')
            ->get();

        foreach ($fundedEscrows as $escrow) {
            try {
                $idempotencyKey = "auto-refund-{$escrow->id}-" . now()->timestamp;

                $escrowService->refund(
                    escrowId: $escrow->id,
                    userId: 'system',
                    reason: 'Automatically refunded due to expiration',
                    idempotencyKey: $idempotencyKey
                );

                Log::info("Auto-refunded expired escrow: {$escrow->id}");
            } catch (\Exception $e) {
                Log::error("Failed to refund expired escrow {$escrow->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Expired escrow processing completed', [
            'cancelled' => $pendingEscrows->count(),
            'refunded' => $fundedEscrows->count(),
        ]);
    }
}
