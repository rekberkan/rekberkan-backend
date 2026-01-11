<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Domain\Ledger\Contracts\LedgerServiceInterface;
use App\Application\Services\LedgerService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind LedgerService interface
        $this->app->singleton(LedgerServiceInterface::class, LedgerService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
