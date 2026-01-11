<?php

namespace App\Console\Commands;

use App\Models\Membership;
use App\Services\MembershipService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * NEW COMMAND: Auto-renew memberships yang akan expire.
 * 
 * Schedule di app/Console/Kernel.php:
 * $schedule->command('memberships:renew')->daily();
 */
class RenewMemberships extends Command
{
    protected $signature = 'memberships:renew';
    protected $description = 'Auto-renew expiring memberships';

    public function __construct(
        private MembershipService $membershipService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Starting membership renewal process...');

        // Find memberships expiring in next 24 hours with auto_renew enabled
        $expiringMemberships = Membership::where('status', 'active')
            ->where('auto_renew', true)
            ->where('expires_at', '<=', now()->addDay())
            ->where('expires_at', '>', now())
            ->get();

        $this->info("Found {$expiringMemberships->count()} memberships to renew.");

        $renewed = 0;
        $failed = 0;

        foreach ($expiringMemberships as $membership) {
            try {
                $this->membershipService->renew($membership);
                $renewed++;
                
                $this->info("✓ Renewed membership for user {$membership->user_id}");
            } catch (\Exception $e) {
                $failed++;
                
                $this->error("✗ Failed to renew for user {$membership->user_id}: {$e->getMessage()}");
                
                Log::error('Membership auto-renewal failed', [
                    'membership_id' => $membership->id,
                    'user_id' => $membership->user_id,
                    'error' => $e->getMessage(),
                ]);

                // Mark as failed renewal
                $membership->update([
                    'auto_renew' => false,
                    'metadata' => array_merge(
                        $membership->metadata ?? [],
                        ['renewal_failed_at' => now()->toIso8601String()]
                    ),
                ]);
            }
        }

        $this->info("\nRenewal complete: {$renewed} succeeded, {$failed} failed.");

        return self::SUCCESS;
    }
}
