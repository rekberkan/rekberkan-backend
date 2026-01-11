<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Run with: php artisan db:seed
     * 
     * PRODUCTION SAFETY:
     * - Never seed production data automatically
     * - Use feature flags for conditional seeding
     * - All seeders must be idempotent
     */
    public function run(): void
    {
        // Only seed in non-production environments
        if (app()->environment('production')) {
            $this->command->warn('Seeding is disabled in production environment');

            return;
        }

        $this->call([
            // Add your seeders here
            // Example: TenantSeeder::class,
        ]);
    }
}
