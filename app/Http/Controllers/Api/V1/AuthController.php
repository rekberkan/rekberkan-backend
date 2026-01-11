<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Application\Services\AuthService;
use App\Http\Resources\AuthResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService
    ) {}

    /**
     * Register new user
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->authService->register(
            tenantId: (int) $request->header('X-Tenant-ID'),
            data: array_merge($request->validated(), [
                'idempotency_key' => $request->header('X-Idempotency-Key')
                    ?? "register-{$request->email}-" . time(),
            ])
        );

        return response()->json([
            'data' => new AuthResource($user),
        ], 201);
    }

    /**
     * Login user
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login(
            email: $request->email,
            password: $request->password,
            request: $request
        );

        return response()->json([
            'data' => [
                'user' => new AuthResource($result['user']),
                'access_token' => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'expires_in' => $result['expires_in'],
            ],
        ]);
    }

    /**
     * Refresh access token
     */
    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        $result = $this->authService->refresh(
            refreshTokenId: $request->refresh_token,
            request: $request
        );

        return response()->json([
            'data' => [
                'access_token' => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'expires_in' => $result['expires_in'],
            ],
        ]);
    }

    /**
     * Logout user
     */
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout(
            user: $request->user(),
            refreshTokenId: $request->input('refresh_token')
        );

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Get current user
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new AuthResource($request->user()),
        ]);
    }
}
