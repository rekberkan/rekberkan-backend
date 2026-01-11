<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;

/**
 * JWT Authentication Middleware with Token Blacklist
 * 
 * SECURITY FIX: Bug #6 - Token revocation via Redis blacklist
 */
final class JwtAuthenticate
{
    public function handle(Request $request, Closure $next)
    {
        try {
            // Parse and get the token
            $token = JWTAuth::getToken();
            
            if (!$token) {
                return $this->unauthorized('Token not provided');
            }

            // SECURITY FIX: Check if token is blacklisted (revoked)
            $tokenString = $token->get();
            if (Cache::has("jwt_blacklist:{$tokenString}")) {
                return response()->json([
                    'type' => 'https://rekberkan.com/errors/token-revoked',
                    'title' => 'Token Revoked',
                    'status' => 401,
                    'detail' => 'This token has been revoked. Please login again.',
                ], 401);
            }

            // Authenticate user
            $user = JWTAuth::parseToken()->authenticate();

            if (!$user) {
                return $this->unauthorized('User not found');
            }

            // Check if account is frozen
            if ($user->frozen_at) {
                return response()->json([
                    'type' => 'https://rekberkan.com/errors/account-frozen',
                    'title' => 'Account Frozen',
                    'status' => 403,
                    'detail' => 'Your account has been frozen. Contact support.',
                ], 403);
            }

            // Store user in request
            $request->setUserResolver(fn() => $user);

        } catch (TokenExpiredException $e) {
            return response()->json([
                'type' => 'https://rekberkan.com/errors/token-expired',
                'title' => 'Token Expired',
                'status' => 401,
                'detail' => 'Your session has expired. Please refresh your token.',
            ], 401);
        } catch (JWTException $e) {
            return $this->unauthorized('Invalid token');
        }

        return $next($request);
    }

    private function unauthorized(string $message): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'type' => 'https://rekberkan.com/errors/unauthenticated',
            'title' => 'Unauthenticated',
            'status' => 401,
            'detail' => $message,
        ], 401);
    }
}
