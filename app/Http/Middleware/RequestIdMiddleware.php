<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

final class RequestIdMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $requestId = $request->header('X-Request-Id') ?? (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);

        // Add to log context
        Log::withContext([
            'request_id' => $requestId,
            'tenant_id' => $request->attributes->get('tenant_id'),
            'user_id' => $request->user()?->id,
        ]);

        $response = $next($request);

        // Add request ID to response headers
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
