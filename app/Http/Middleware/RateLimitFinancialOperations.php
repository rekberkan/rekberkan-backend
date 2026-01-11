<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class RateLimitFinancialOperations
{
    /**
     * Handle an incoming request.
     * 
     * SECURITY FIX: Enforce rate limiting pada semua financial endpoints.
     * 
     * Rate limits:
     * - Deposits: 10 per hour
     * - Withdrawals: 5 per hour  
     * - Escrow: 20 per hour
     */
    public function handle(Request $request, Closure $next, string $operation = 'default'): Response
    {
        $userId = $request->user()?->id ?? $request->ip();
        $key = "financial:{$operation}:{$userId}";

        // Define limits per operation type
        $limits = [
            'deposit' => [10, 3600],      // 10 per hour
            'withdrawal' => [5, 3600],     // 5 per hour
            'escrow' => [20, 3600],        // 20 per hour
            'default' => [30, 3600],       // 30 per hour
        ];

        [$maxAttempts, $decaySeconds] = $limits[$operation] ?? $limits['default'];

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'success' => false,
                'message' => 'Too many requests. Please try again later.',
                'retry_after' => $retryAfter,
            ], 429)->header('Retry-After', $retryAfter);
        }

        RateLimiter::hit($key, $decaySeconds);

        $response = $next($request);

        // Add rate limit headers
        $remaining = RateLimiter::remaining($key, $maxAttempts);
        $response->headers->set('X-RateLimit-Limit', $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', $remaining);

        return $response;
    }
}
