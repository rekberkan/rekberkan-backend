<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class FinancialRateLimiter
{
    public function handle(Request $request, Closure $next)
    {
        $key = 'financial:' . ($request->user()?->id ?? $request->ip());

        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json([
                'type' => 'https://rekberkan.com/errors/financial-rate-limit',
                'title' => 'Rate Limit Exceeded',
                'status' => 429,
                'detail' => 'Too many financial operations. Please try again later.',
            ], 429);
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
