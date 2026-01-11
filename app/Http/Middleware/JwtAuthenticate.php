<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;

final class JwtAuthenticate
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            if (!$user) {
                return $this->unauthorized('User not found');
            }

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
