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
     * 
     * SECURITY FIX: Bug #7 - Enhanced rate limiting with IP-based protection
     */
    protected function configureRateLimiting(): void
    {
        // Default API rate limiter
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // SECURITY FIX: Bug #7 - Auth operations with strict IP-based rate limiting
        RateLimiter::for('auth', function (Request $request) {
            $maxAttempts = config('rate-limiting.auth.max_attempts', 10);
            $decayMinutes = config('rate-limiting.auth.decay_minutes', 10);

            return [
                // Per IP limit (prevents distributed attacks from same IP)
                Limit::perMinutes($decayMinutes, $maxAttempts)
                    ->by($request->ip())
                    ->response(function () {
                        return response()->json([
                            'success' => false,
                            'message' => 'Too many authentication attempts. Please try again later.',
                        ], 429);
                    }),
                // Per email limit (prevents targeted account attacks)
                Limit::perMinutes($decayMinutes, $maxAttempts)
                    ->by($request->input('email', 'unknown'))
                    ->response(function () {
                        return response()->json([
                            'success' => false,
                            'message' => 'Too many attempts for this account. Please try again later.',
                        ], 429);
                    }),
            ];
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

        // SECURITY FIX: Bug #7 - Admin operations with multi-layer protection
        RateLimiter::for('admin', function (Request $request) {
            $maxAttempts = config('rate-limiting.admin.max_attempts', 100);
            $decayMinutes = config('rate-limiting.admin.decay_minutes', 60);

            return [
                // Per user limit (normal usage)
                Limit::perMinutes($decayMinutes, $maxAttempts)
                    ->by($request->user()?->id ?: 'guest')
                    ->response(function () {
                        return response()->json([
                            'success' => false,
                            'message' => 'Admin rate limit exceeded. Please try again later.',
                        ], 429);
                    }),
                // Per IP limit (stricter for brute force protection)
                Limit::perMinutes(1, 20)
                    ->by($request->ip())
                    ->response(function () {
                        return response()->json([
                            'success' => false,
                            'message' => 'Too many admin requests from this IP. Temporary block applied.',
                        ], 429);
                    }),
            ];
        });

        // SECURITY FIX: Bug #7 - Admin authentication (login to admin panel)
        RateLimiter::for('admin-auth', function (Request $request) {
            return [
                // Strict IP-based limit: 5 attempts per 10 minutes
                Limit::perMinutes(10, 5)
                    ->by($request->ip())
                    ->response(function () {
                        return response()->json([
                            'success' => false,
                            'message' => 'Too many admin login attempts. Account may be locked.',
                        ], 429);
                    }),
            ];
        });
    }
}
