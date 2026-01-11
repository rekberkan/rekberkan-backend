<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register core banking services
        $this->app->singleton(
            \App\Domain\Ledger\Contracts\LedgerServiceInterface::class,
            \App\Application\Services\LedgerService::class
        );

        $this->app->singleton(
            \App\Domain\Risk\Contracts\RiskEngineInterface::class,
            \App\Application\Services\RiskEngine::class
        );
    }

    public function boot(): void
    {
        // Log slow queries in non-production
        if (!app()->isProduction()) {
            DB::listen(function (QueryExecuted $query): void {
                if ($query->time > 100) {
                    Log::warning('Slow query detected', [
                        'sql' => $query->sql,
                        'bindings' => $query->bindings,
                        'time' => $query->time,
                    ]);
                }
            });
        }

        // Set PostgreSQL session variables for tenant isolation
        DB::afterConnecting(function (Connection $connection): void {
            if ($connection->getDriverName() === 'pgsql') {
                $connection->statement('SET SESSION statement_timeout = 30000');
                $connection->statement('SET SESSION idle_in_transaction_session_timeout = 60000');
            }
        });
    }
}
