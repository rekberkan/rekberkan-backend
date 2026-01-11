<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    /**
     * Liveness probe - indicates the application is running.
     * Kubernetes uses this to restart unhealthy pods.
     */
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Readiness probe - indicates the application can serve traffic.
     * Checks critical dependencies: database and cache.
     */
    public function ready(): JsonResponse
    {
        $services = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
        ];

        $allHealthy = collect($services)->every(fn ($status) => $status === 'healthy');

        return response()->json([
            'status' => $allHealthy ? 'ready' : 'not_ready',
            'services' => $services,
            'timestamp' => now()->toIso8601String(),
        ], $allHealthy ? 200 : 503);
    }

    private function checkDatabase(): string
    {
        try {
            DB::connection()->getPdo();
            DB::select('SELECT 1');

            return 'healthy';
        } catch (Throwable) {
            return 'unhealthy';
        }
    }

    private function checkRedis(): string
    {
        try {
            Redis::ping();

            return 'healthy';
        } catch (Throwable) {
            return 'unhealthy';
        }
    }
}
