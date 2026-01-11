<?php

declare(strict_types=1);

namespace App\Console;

use App\Jobs\ProcessAutoReleaseEscrows;
use App\Jobs\ProcessExpiredEscrows;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Process auto-release escrows every 5 minutes
        $schedule->job(new ProcessAutoReleaseEscrows())
            ->everyFiveMinutes()
            ->withoutOverlapping(5)
            ->onOneServer();

        // Process expired escrows every hour
        $schedule->job(new ProcessExpiredEscrows())
            ->hourly()
            ->withoutOverlapping(10)
            ->onOneServer();

        // Clean up old security events (keep 90 days)
        $schedule->command('db:prune', ['--model' => 'App\Models\SecurityEvent'])
            ->daily()
            ->at('02:00');

        // Clean up expired idempotency keys
        $schedule->command('db:prune', ['--model' => 'App\Models\IdempotencyKey'])
            ->daily()
            ->at('03:00');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
