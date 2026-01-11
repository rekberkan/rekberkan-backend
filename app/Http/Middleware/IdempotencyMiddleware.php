<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

final class IdempotencyMiddleware
{
    private const CACHE_TTL = 86400; // 24 hours

    public function handle(Request $request, Closure $next)
    {
        // Only apply to state-changing methods
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return $next($request);
        }

        $idempotencyKey = $request->header('X-Idempotency-Key');

        if (!$idempotencyKey) {
            return response()->json([
                'type' => 'https://rekberkan.com/errors/idempotency-key-required',
                'title' => 'Idempotency Key Required',
                'status' => 400,
                'detail' => 'X-Idempotency-Key header is required for this operation.',
            ], 400);
        }

        $tenantId = $request->attributes->get('tenant_id');
        $userId = $request->user()?->id;
        $cacheKey = "idempotency:{$tenantId}:{$userId}:{$idempotencyKey}";

        // Check if request was already processed
        $cachedResponse = Cache::get($cacheKey);
        if ($cachedResponse) {
            return response()->json($cachedResponse['body'], $cachedResponse['status'])
                ->withHeaders(['X-Idempotency-Replayed' => 'true']);
        }

        // Check database for duplicate
        $exists = DB::table('idempotency_keys')
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $idempotencyKey)
            ->exists();

        if ($exists) {
            return response()->json([
                'type' => 'https://rekberkan.com/errors/duplicate-idempotency-key',
                'title' => 'Duplicate Request',
                'status' => 409,
                'detail' => 'This idempotency key has already been used.',
            ], 409);
        }

        // Process request
        $response = $next($request);

        // Store successful responses (2xx)
        if ($response->isSuccessful()) {
            $responseData = [
                'body' => json_decode($response->getContent(), true),
                'status' => $response->getStatusCode(),
            ];

            Cache::put($cacheKey, $responseData, self::CACHE_TTL);

            // Persist to database asynchronously
            dispatch(function () use ($tenantId, $userId, $idempotencyKey, $request, $response) {
                DB::table('idempotency_keys')->insert([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'idempotency_key' => $idempotencyKey,
                    'request_path' => $request->path(),
                    'request_method' => $request->method(),
                    'response_status' => $response->getStatusCode(),
                    'created_at' => now(),
                ]);
            })->afterResponse();
        }

        return $response;
    }
}
