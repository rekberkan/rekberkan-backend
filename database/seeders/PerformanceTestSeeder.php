<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;

class PerformanceTestSeeder extends Seeder
{
    /**
     * Seed the database with test data for performance testing.
     */
    public function run(): void
    {
        $this->command->info('Seeding performance test data...');

        // Create tenants
        $this->command->info('Creating tenants...');
        $tenants = Tenant::factory()->count(10)->create();

        // Create users for each tenant
        $this->command->info('Creating users...');
        foreach ($tenants as $tenant) {
            User::factory()
                ->count(50)
                ->create(['tenant_id' => $tenant->id]);
        }

        // Create transactions (bulk insert for performance)
        $this->command->info('Creating transactions...');
        $batchSize = 1000;
        $totalTransactions = 10000;
        
        for ($i = 0; $i < $totalTransactions; $i += $batchSize) {
            $transactions = [];
            
            for ($j = 0; $j < $batchSize; $j++) {
                $tenant = $tenants->random();
                $user = User::where('tenant_id', $tenant->id)->inRandomOrder()->first();
                
                $transactions[] = [
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'amount' => rand(10000, 10000000),
                    'status' => collect(['pending', 'completed', 'cancelled'])->random(),
                    'description' => 'Performance test transaction',
                    'created_at' => now()->subDays(rand(0, 365)),
                    'updated_at' => now()->subDays(rand(0, 365)),
                ];
            }
            
            Transaction::insert($transactions);
            $this->command->info("Inserted batch: " . ($i + $batchSize));
        }

        $this->command->info('Performance test data seeded successfully!');
    }
}
