<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        Commands\VerifyAuditChain::class,
    ];

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('audit:verify')
            ->daily()
            ->at('02:00')
            ->emailOutputOnFailure(config('mail.audit_alert_email'));

        $schedule->command('step-up:cleanup')
            ->hourly();
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
