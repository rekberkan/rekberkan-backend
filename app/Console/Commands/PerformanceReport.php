<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PerformanceReport extends Command
{
    protected $signature = 'performance:report {--output=performance-report.html}';
    protected $description = 'Generate performance analysis report';

    public function handle(): int
    {
        $this->info('Generating performance report...');

        $report = $this->generateReport();
        $outputFile = $this->option('output');

        file_put_contents($outputFile, $report);

        $this->info("Performance report generated: {$outputFile}");

        return Command::SUCCESS;
    }

    private function generateReport(): string
    {
        $stats = $this->collectStats();

        return view('reports.performance', $stats)->render();
    }

    private function collectStats(): array
    {
        return [
            'database_size' => $this->getDatabaseSize(),
            'table_sizes' => $this->getTableSizes(),
            'index_usage' => $this->getIndexUsage(),
            'slow_queries' => $this->getSlowQueries(),
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    private function getDatabaseSize(): string
    {
        $result = DB::select(
            "SELECT pg_size_pretty(pg_database_size(?)) as size",
            [config('database.connections.pgsql.database')]
        );

        return $result[0]->size ?? 'N/A';
    }

    private function getTableSizes(): array
    {
        $tables = DB::select("
            SELECT 
                schemaname,
                tablename,
                pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) AS size,
                pg_total_relation_size(schemaname||'.'||tablename) AS size_bytes
            FROM pg_tables
            WHERE schemaname = 'public'
            ORDER BY size_bytes DESC
            LIMIT 10
        ");

        return collect($tables)->map(fn($t) => [
            'name' => $t->tablename,
            'size' => $t->size,
        ])->toArray();
    }

    private function getIndexUsage(): array
    {
        $indexes = DB::select("
            SELECT
                schemaname,
                tablename,
                indexname,
                idx_scan as scans,
                idx_tup_read as tuples_read,
                idx_tup_fetch as tuples_fetched
            FROM pg_stat_user_indexes
            WHERE schemaname = 'public'
            ORDER BY idx_scan DESC
            LIMIT 20
        ");

        return collect($indexes)->toArray();
    }

    private function getSlowQueries(): array
    {
        // Read from performance log
        $logFile = storage_path('logs/performance.log');
        
        if (!file_exists($logFile)) {
            return [];
        }

        $content = file_get_contents($logFile);
        $lines = explode("\n", $content);
        
        return collect($lines)
            ->filter(fn($line) => str_contains($line, 'Slow query'))
            ->take(10)
            ->values()
            ->toArray();
    }
}
