<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService
    ) {}

    /**
     * Register a new user
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            // Get tenant ID from validated request context
            $tenantId = $this->getTenantId($request);

            if (!$tenantId || $tenantId <= 0) {
                return response()->json([
                    'error' => 'Invalid Tenant',
                    'message' => 'Valid tenant context is required for registration',
                ], 400);
            }

            // Validate tenant ID in request body matches context
            if ($request->has('tenant_id') && $request->input('tenant_id') != $tenantId) {
                return response()->json([
                    'error' => 'Tenant Mismatch',
                    'message' => 'Tenant ID in request does not match authenticated context',
                ], 400);
            }

            $result = $this->authService->register(
                $request->validated(),
                $tenantId
            );

            return response()->json([
                'message' => 'Registration successful',
                'data' => [
                    'user' => $result['user'],
                    'wallet' => $result['wallet'],
                ],
                'token' => $result['token'],
                'token_type' => $result['token_type'],
                'expires_in' => $result['expires_in'],
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Validation Error',
                'message' => 'The given data was invalid',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Registration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Registration Failed',
                'message' => 'An error occurred during registration',
            ], 500);
        }
    }

    /**
     * Login user with tenant scoping
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            // Get tenant ID from request context
            $tenantId = $this->getTenantId($request);

            if (!$tenantId || $tenantId <= 0) {
                return response()->json([
                    'error' => 'Invalid Tenant',
                    'message' => 'Valid tenant context is required for login',
                ], 400);
            }

            $result = $this->authService->login(
                $request->only(['email', 'password']),
                $tenantId
            );

            return response()->json([
                'message' => 'Login successful',
                'data' => [
                    'user' => $result['user'],
                ],
                'token' => $result['token'],
                'token_type' => $result['token_type'],
                'expires_in' => $result['expires_in'],
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Authentication Failed',
                'message' => 'Invalid credentials',
                'errors' => $e->errors(),
            ], 401);
        } catch (\Exception $e) {
            \Log::error('Login failed', [
                'error' => $e->getMessage(),
                'email' => $request->input('email'),
            ]);

            return response()->json([
                'error' => 'Login Failed',
                'message' => 'An error occurred during login',
            ], 500);
        }
    }

    /**
     * Logout user
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $this->authService->logout();

            return response()->json([
                'message' => 'Logout successful',
            ]);
        } catch (\Exception $e) {
            \Log::error('Logout error', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);

            // Return success even on error to prevent client issues
            return response()->json([
                'message' => 'Logout successful',
            ]);
        }
    }

    /**
     * Refresh authentication token
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            $result = $this->authService->refresh();

            return response()->json([
                'message' => 'Token refreshed successfully',
                'token' => $result['token'],
                'token_type' => $result['token_type'],
                'expires_in' => $result['expires_in'],
            ]);
        } catch (\Exception $e) {
            \Log::error('Token refresh failed', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'error' => 'Token Refresh Failed',
                'message' => $e->getMessage(),
            ], 401);
        }
    }

    /**
     * Get current authenticated user
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['wallet', 'roles']);

        return response()->json([
            'data' => $user,
        ]);
    }

    /**
     * Get tenant ID from request context
     */
    private function getTenantId(Request $request): ?int
    {
        // Priority 1: From middleware-resolved tenant
        if ($tenant = $request->attributes->get('tenant')) {
            return $tenant->id;
        }

        // Priority 2: From config (set by middleware)
        if ($tenantId = config('app.current_tenant_id')) {
            return (int) $tenantId;
        }

        // Priority 3: From header (validated by middleware)
        $headerTenantId = $request->header('X-Tenant-ID');
        if ($headerTenantId && is_numeric($headerTenantId) && (int) $headerTenantId > 0) {
            return (int) $headerTenantId;
        }

        return null;
    }
}
