<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class KillSwitchMiddleware
{
    private const CACHE_TTL = 60; // seconds

    public function handle(Request $request, Closure $next, string $operation)
    {
        $isKilled = Cache::remember(
            "killswitch:{$operation}",
            self::CACHE_TTL,
            fn() => (bool) config("killswitch.{$operation}", false)
        );

        if ($isKilled || config('killswitch.global', false)) {
            return response()->json([
                'type' => 'https://rekberkan.com/errors/operation-disabled',
                'title' => 'Operation Temporarily Disabled',
                'status' => 503,
                'detail' => 'This operation is currently unavailable due to maintenance.',
                'operation' => $operation,
            ], 503);
        }

        return $next($request);
    }
}
