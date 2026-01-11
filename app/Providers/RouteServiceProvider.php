<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // Default API rate limiter
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Auth operations (login, register)
        RateLimiter::for('auth', function (Request $request) {
            $maxAttempts = config('rate-limiting.auth.max_attempts', 10);
            $decayMinutes = config('rate-limiting.auth.decay_minutes', 10);

            return Limit::perMinutes($decayMinutes, $maxAttempts)
                ->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'success' => false,
                        'message' => 'Too many authentication attempts. Please try again later.',
                    ], 429);
                });
        });

        // Deposit operations
        RateLimiter::for('deposit', function (Request $request) {
            $maxAttempts = config('rate-limiting.deposit.max_attempts', 10);
            $decayMinutes = config('rate-limiting.deposit.decay_minutes', 60);

            return Limit::perMinutes($decayMinutes, $maxAttempts)
                ->by($request->user()?->id ?: $request->ip());
        });

        // Withdrawal operations
        RateLimiter::for('withdrawal', function (Request $request) {
            $maxAttempts = config('rate-limiting.withdrawal.max_attempts', 5);
            $decayMinutes = config('rate-limiting.withdrawal.decay_minutes', 60);

            return Limit::perMinutes($decayMinutes, $maxAttempts)
                ->by($request->user()?->id ?: $request->ip());
        });

        // Escrow operations
        RateLimiter::for('escrow', function (Request $request) {
            $maxAttempts = config('rate-limiting.escrow.max_attempts', 20);
            $decayMinutes = config('rate-limiting.escrow.decay_minutes', 60);

            return Limit::perMinutes($decayMinutes, $maxAttempts)
                ->by($request->user()?->id ?: $request->ip());
        });

        // Chat operations
        RateLimiter::for('chat', function (Request $request) {
            $maxAttempts = config('rate-limiting.chat.max_attempts', 120);
            $decayMinutes = config('rate-limiting.chat.decay_minutes', 60);

            return Limit::perMinutes($decayMinutes, $maxAttempts)
                ->by($request->user()?->id ?: $request->ip());
        });

        // Admin operations
        RateLimiter::for('admin', function (Request $request) {
            $maxAttempts = config('rate-limiting.admin.max_attempts', 100);
            $decayMinutes = config('rate-limiting.admin.decay_minutes', 60);

            return Limit::perMinutes($decayMinutes, $maxAttempts)
                ->by($request->user()?->id ?: $request->ip());
        });
    }
}
