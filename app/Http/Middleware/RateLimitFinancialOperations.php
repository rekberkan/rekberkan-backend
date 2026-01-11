<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class RateLimitFinancialOperations
{
    public function handle(Request $request, Closure $next, string $type = 'default'): Response
    {
        $limits = [
            'deposit' => ['attempts' => 10, 'decay' => 60],
            'withdraw' => ['attempts' => 5, 'decay' => 60],
            'transfer' => ['attempts' => 20, 'decay' => 60],
            'escrow_create' => ['attempts' => 30, 'decay' => 60],
            'default' => ['attempts' => 60, 'decay' => 60],
        ];

        $limit = $limits[$type] ?? $limits['default'];
        
        $key = $this->resolveRateLimitKey($request, $type);
        $maxAttempts = $limit['attempts'];
        $decayMinutes = $limit['decay'];

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);
            
            return response()->json([
                'error' => 'Too many requests',
                'message' => "Rate limit exceeded. Please try again in {$seconds} seconds.",
                'retry_after' => $seconds,
            ], 429);
        }

        RateLimiter::hit($key, $decayMinutes * 60);

        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', RateLimiter::remaining($key, $maxAttempts));

        return $response;
    }

    protected function resolveRateLimitKey(Request $request, string $type): string
    {
        $user = $request->user();
        $tenantId = $request->header('X-Tenant-ID');
        
        if ($user) {
            return "financial:{$type}:user:{$user->id}:tenant:{$tenantId}";
        }
        
        return "financial:{$type}:ip:{$request->ip()}:tenant:{$tenantId}";
    }
}
