<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PerformanceAnalyzeQueries extends Command
{
    protected $signature = 'performance:analyze-queries';
    protected $description = 'Analyze slow queries and database performance';

    public function handle(): int
    {
        $this->info('Analyzing database query performance...');

        // Enable query logging
        DB::enableQueryLog();

        // Run common queries
        $this->testTransactionQueries();
        $this->testUserQueries();
        $this->testTenantQueries();

        // Analyze queries
        $queries = DB::getQueryLog();
        $slowQueries = collect($queries)->filter(fn($q) => $q['time'] > 100);

        if ($slowQueries->isEmpty()) {
            $this->info('✅ No slow queries detected (all < 100ms)');
            return Command::SUCCESS;
        }

        $this->warn("⚠️  Found {$slowQueries->count()} slow queries:");
        
        foreach ($slowQueries as $query) {
            $this->line("");
            $this->line("Query: {$query['query']}");
            $this->line("Time: {$query['time']}ms");
            $this->line("Bindings: " . json_encode($query['bindings']));
            
            // Log to file
            Log::channel('performance')->warning('Slow query detected', [
                'query' => $query['query'],
                'time' => $query['time'],
                'bindings' => $query['bindings'],
            ]);
        }

        return Command::FAILURE;
    }

    private function testTransactionQueries(): void
    {
        $this->info('Testing transaction queries...');
        
        // Test with pagination
        \App\Models\Transaction::with('user', 'tenant')
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        // Test with filters
        \App\Models\Transaction::where('amount', '>', 100000)
            ->whereDate('created_at', '>=', now()->subDays(30))
            ->count();
    }

    private function testUserQueries(): void
    {
        $this->info('Testing user queries...');
        
        \App\Models\User::with('transactions')
            ->where('status', 'active')
            ->limit(100)
            ->get();
    }

    private function testTenantQueries(): void
    {
        $this->info('Testing tenant queries...');
        
        \App\Models\Tenant::withCount('users', 'transactions')
            ->get();
    }
}
