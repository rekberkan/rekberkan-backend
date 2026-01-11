<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class RateLimitAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->resolveRateLimitKey($request);
        $maxAttempts = 5;
        $decayMinutes = 15;

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);
            
            return response()->json([
                'error' => 'Too many login attempts',
                'message' => "Account temporarily locked. Please try again in {$seconds} seconds.",
                'retry_after' => $seconds,
            ], 429);
        }

        $response = $next($request);

        if ($response->isSuccessful()) {
            RateLimiter::clear($key);
        } else {
            RateLimiter::hit($key, $decayMinutes * 60);
        }

        return $response;
    }

    protected function resolveRateLimitKey(Request $request): string
    {
        $email = $request->input('email', 'unknown');
        $ip = $request->ip();
        
        return "auth:login:email:{$email}:ip:{$ip}";
    }
}
