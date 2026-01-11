<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    protected function configureRateLimiting(): void
    {
        // API rate limiting
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute((int) config('app.rate_limit.api', 60))
                ->by($request->user()?->id ?: $request->ip())
                ->response(function () {
                    return response()->json([
                        'type' => 'https://rekberkan.com/errors/rate-limit-exceeded',
                        'title' => 'Rate Limit Exceeded',
                        'status' => 429,
                        'detail' => 'Too many requests. Please slow down.',
                    ], 429);
                });
        });

        // Authentication rate limiting
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute((int) config('app.rate_limit.auth', 5))
                ->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'type' => 'https://rekberkan.com/errors/auth-rate-limit',
                        'title' => 'Authentication Rate Limit Exceeded',
                        'status' => 429,
                        'detail' => 'Too many authentication attempts.',
                    ], 429);
                });
        });

        // Financial operations rate limiting (stricter)
        RateLimiter::for('financial', function (Request $request) {
            return Limit::perMinute((int) config('app.rate_limit.financial', 10))
                ->by($request->user()?->id ?: $request->ip())
                ->response(function () {
                    return response()->json([
                        'type' => 'https://rekberkan.com/errors/financial-rate-limit',
                        'title' => 'Financial Operation Rate Limit',
                        'status' => 429,
                        'detail' => 'Too many financial operations.',
                    ], 429);
                });
        });
    }
}
