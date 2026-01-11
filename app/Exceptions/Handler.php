<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
        'pin',
        'token',
        'secret',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e): void {
            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }
        });
    }

    /**
     * Render API exception as RFC 7807 Problem Details
     */
    public function apiRender(Throwable $e, $request): JsonResponse
    {
        $requestId = $request->header('X-Request-Id') ?? $request->attributes->get('request_id');

        // Validation errors
        if ($e instanceof ValidationException) {
            return response()->json([
                'type' => 'https://rekberkan.com/errors/validation-failed',
                'title' => 'Validation Failed',
                'status' => 422,
                'detail' => 'The request contains invalid data.',
                'errors' => $e->errors(),
                'request_id' => $requestId,
            ], 422);
        }

        // Authentication errors
        if ($e instanceof AuthenticationException) {
            return response()->json([
                'type' => 'https://rekberkan.com/errors/unauthenticated',
                'title' => 'Unauthenticated',
                'status' => 401,
                'detail' => $e->getMessage() ?: 'Authentication is required.',
                'request_id' => $requestId,
            ], 401);
        }

        // Model not found
        if ($e instanceof ModelNotFoundException) {
            return response()->json([
                'type' => 'https://rekberkan.com/errors/resource-not-found',
                'title' => 'Resource Not Found',
                'status' => 404,
                'detail' => 'The requested resource does not exist.',
                'request_id' => $requestId,
            ], 404);
        }

        // HTTP exceptions
        if ($e instanceof HttpException) {
            return response()->json([
                'type' => 'https://rekberkan.com/errors/http-error',
                'title' => class_basename($e),
                'status' => $e->getStatusCode(),
                'detail' => $e->getMessage() ?: 'An HTTP error occurred.',
                'request_id' => $requestId,
            ], $e->getStatusCode());
        }

        // Domain exceptions (custom business logic errors)
        if ($e instanceof \App\Exceptions\DomainException) {
            return response()->json([
                'type' => $e->getType(),
                'title' => $e->getTitle(),
                'status' => $e->getStatusCode(),
                'detail' => $e->getMessage(),
                'context' => $e->getContext(),
                'request_id' => $requestId,
            ], $e->getStatusCode());
        }

        // Default server error (hide details in production)
        $detail = app()->isProduction()
            ? 'An internal server error occurred.'
            : $e->getMessage();

        return response()->json([
            'type' => 'https://rekberkan.com/errors/internal-error',
            'title' => 'Internal Server Error',
            'status' => 500,
            'detail' => $detail,
            'request_id' => $requestId,
        ], 500);
    }
}
