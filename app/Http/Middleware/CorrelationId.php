<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CorrelationId
{
    /**
     * Handle an incoming request and inject correlation ID.
     * 
     * Correlation ID enables request tracing across services and logs.
     * Priority: X-Correlation-ID header > X-Request-ID > generate new
     */
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header('X-Correlation-ID')
            ?? $request->header('X-Request-ID')
            ?? (string) Str::uuid();

        // Store in request for controllers
        $request->attributes->set('correlation_id', $correlationId);

        // Add to log context
        Log::shareContext([
            'correlation_id' => $correlationId,
            'tenant_id' => $request->attributes->get('tenant_id'),
            'user_id' => $request->user()?->id,
        ]);

        $response = $next($request);

        // Add to response headers for client tracking
        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }
}
