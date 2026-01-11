<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyMiddleware
{
    /**
     * Methods that should use idempotency
     */
    private const IDEMPOTENT_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * TTL for idempotency cache (24 hours)
     */
    private const CACHE_TTL = 86400;

    /**
     * Handle an incoming request with proper idempotency.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only apply to idempotent methods
        if (!in_array($request->method(), self::IDEMPOTENT_METHODS)) {
            return $next($request);
        }

        // Get idempotency key from header
        $idempotencyKey = $request->header('Idempotency-Key');

        if (!$idempotencyKey) {
            // Generate deterministic key for registration endpoints
            if ($this->isRegistrationEndpoint($request)) {
                $idempotencyKey = $this->generateSecureIdempotencyKey($request);
            } else {
                // No idempotency for other endpoints without explicit key
                return $next($request);
            }
        }

        // Validate key format (prevent injection)
        if (!$this->isValidIdempotencyKey($idempotencyKey)) {
            return response()->json([
                'error' => 'Invalid Idempotency Key',
                'message' => 'Idempotency key must be a valid UUID or secure hash',
            ], 400);
        }

        // Build composite cache key (includes tenant, user, path, method)
        $cacheKey = $this->buildCacheKey($request, $idempotencyKey);

        // Check cache first
        $cachedResponse = Cache::get($cacheKey);
        if ($cachedResponse) {
            return $this->restoreResponse($cachedResponse);
        }

        // Check database for persistent idempotency
        $dbRecord = $this->checkDatabaseIdempotency($request, $idempotencyKey);
        if ($dbRecord) {
            return $this->restoreResponse($dbRecord['response']);
        }

        // Process request
        $response = $next($request);

        // Cache successful responses and client errors (4xx)
        // Don't cache server errors (5xx) to allow retries
        if ($response->getStatusCode() < 500) {
            $this->storeResponse($cacheKey, $response, $request, $idempotencyKey);
        }

        // Add idempotency headers to response
        $response->headers->set('Idempotency-Key', $idempotencyKey);
        $response->headers->set('X-Idempotent-Replay', $cachedResponse ? 'true' : 'false');

        return $response;
    }

    /**
     * Check if endpoint is registration endpoint
     */
    private function isRegistrationEndpoint(Request $request): bool
    {
        return $request->is('api/*/auth/register') || $request->is('api/auth/register');
    }

    /**
     * Generate secure idempotency key without PII
     */
    private function generateSecureIdempotencyKey(Request $request): string
    {
        // Use secure components without exposing PII
        $components = [
            $request->ip(),
            $request->userAgent(),
            $request->header('Accept-Language'),
            config('app.key'),
            // Add timestamp window (5 minute buckets) for some uniqueness
            floor(time() / 300),
        ];

        // Don't include email or other PII
        return hash('sha256', implode('|', $components));
    }

    /**
     * Validate idempotency key format
     */
    private function isValidIdempotencyKey(string $key): bool
    {
        // Accept UUID v4
        if (Str::isUuid($key)) {
            return true;
        }

        // Accept SHA-256 hash
        if (preg_match('/^[a-f0-9]{64}$/i', $key)) {
            return true;
        }

        // Accept alphanumeric with dashes (max 64 chars)
        if (preg_match('/^[a-zA-Z0-9-]{1,64}$/', $key)) {
            return true;
        }

        return false;
    }

    /**
     * Build composite cache key including context
     */
    private function buildCacheKey(Request $request, string $idempotencyKey): string
    {
        $tenantId = $this->getTenantId($request);
        $userId = $request->user()?->id ?? 'anonymous';
        $path = $request->path();
        $method = $request->method();

        // Include all context to prevent cross-contamination
        return sprintf(
            'idempotency:%s:%s:%s:%s:%s',
            $tenantId,
            $userId,
            $method,
            hash('sha256', $path),
            $idempotencyKey
        );
    }

    /**
     * Get tenant ID from request
     */
    private function getTenantId(Request $request): int
    {
        return $request->attributes->get('tenant')?->id 
            ?? $request->user()?->tenant_id 
            ?? (int) $request->header('X-Tenant-ID')
            ?? 0;
    }

    /**
     * Check database for idempotency record
     */
    private function checkDatabaseIdempotency(Request $request, string $idempotencyKey): ?array
    {
        $tenantId = $this->getTenantId($request);
        $path = $request->path();
        $method = $request->method();

        $record = DB::table('idempotency_keys')
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $idempotencyKey)
            ->where('request_path', $path)
            ->where('request_method', $method)
            ->where('created_at', '>', now()->subDay())
            ->first();

        if (!$record) {
            return null;
        }

        return [
            'response' => [
                'status' => $record->response_status,
                'headers' => json_decode($record->response_headers, true),
                'body' => $record->response_body,
            ],
        ];
    }

    /**
     * Store response in cache and database
     */
    private function storeResponse(
        string $cacheKey,
        Response $response,
        Request $request,
        string $idempotencyKey
    ): void {
        $responseData = [
            'status' => $response->getStatusCode(),
            'headers' => $response->headers->all(),
            'body' => $response->getContent(),
        ];

        // Cache in memory
        Cache::put($cacheKey, $responseData, self::CACHE_TTL);

        // Persist to database
        try {
            DB::table('idempotency_keys')->updateOrInsert(
                [
                    'tenant_id' => $this->getTenantId($request),
                    'idempotency_key' => $idempotencyKey,
                    'request_path' => $request->path(),
                    'request_method' => $request->method(),
                ],
                [
                    'response_status' => $response->getStatusCode(),
                    'response_headers' => json_encode($response->headers->all()),
                    'response_body' => $response->getContent(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        } catch (\Exception $e) {
            \Log::error('Failed to store idempotency record', [
                'error' => $e->getMessage(),
                'key' => $idempotencyKey,
            ]);
        }
    }

    /**
     * Restore response from cached data
     */
    private function restoreResponse(array $data): Response
    {
        $response = response($data['body'], $data['status']);

        // Restore headers
        if (isset($data['headers'])) {
            foreach ($data['headers'] as $key => $values) {
                $response->headers->set($key, $values);
            }
        }

        // Mark as replayed
        $response->headers->set('X-Idempotent-Replay', 'true');

        return $response;
    }
}
