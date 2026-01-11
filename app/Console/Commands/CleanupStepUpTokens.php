<?php

namespace App\Console\Commands;

use App\Services\StepUpAuthService;
use Illuminate\Console\Command;

class CleanupStepUpTokens extends Command
{
    protected $signature = 'step-up:cleanup';

    protected $description = 'Cleanup expired step-up authentication tokens';

    public function handle(StepUpAuthService $service): int
    {
        $deleted = $service->cleanupExpired();
        
        $this->info("Cleaned up {$deleted} expired step-up tokens");
        
        return 0;
    }
}
