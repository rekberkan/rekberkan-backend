<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/health',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \App\Http\Middleware\ForceJsonResponse::class,
            \App\Http\Middleware\RequestIdMiddleware::class,
            \App\Http\Middleware\TenantResolution::class,
        ]);

        $middleware->alias([
            'auth.jwt' => \App\Http\Middleware\JwtAuthenticate::class,
            'auth.admin' => \App\Http\Middleware\AdminAuthenticate::class,
            'check.idempotency' => \App\Http\Middleware\IdempotencyMiddleware::class,
            'check.killswitch' => \App\Http\Middleware\KillSwitchMiddleware::class,
            'rate.financial' => \App\Http\Middleware\FinancialRateLimiter::class,
        ]);

        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (Throwable $e, $request) {
            if ($request->is('api/*')) {
                return app(\App\Exceptions\Handler::class)->apiRender($e, $request);
            }
        });
    })
    ->create();
