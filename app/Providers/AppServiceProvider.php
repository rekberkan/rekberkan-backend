<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Domain\Ledger\Contracts\LedgerServiceInterface;
use App\Application\Services\LedgerService;
use RuntimeException;

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
        // SECURITY FIX: Bug #2 - Validate APP_KEY in production
        // Prevents deployment without encryption key which would cause security issues
        if ($this->app->environment('production')) {
            $appKey = config('app.key');
            
            if (empty($appKey)) {
                throw new RuntimeException(
                    'APP_KEY must be set in production environment. '.
                    'Run: php artisan key:generate'
                );
            }

            // Validate key format (should be base64:... for Laravel)
            if (!str_starts_with($appKey, 'base64:')) {
                throw new RuntimeException(
                    'APP_KEY must be properly formatted. '.
                    'Run: php artisan key:generate'
                );
            }

            // Validate minimum key length (256-bit = 32 bytes)
            $keyData = base64_decode(substr($appKey, 7));
            if (strlen($keyData) < 32) {
                throw new RuntimeException(
                    'APP_KEY must be at least 256 bits (32 bytes). '.
                    'Run: php artisan key:generate'
                );
            }
        }
    }
}
