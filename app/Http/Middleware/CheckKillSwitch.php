<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CheckKillSwitch
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        // Check global kill switch
        if (config('killswitch.global')) {
            return response()->json([
                'type' => 'https://rekberkan.com/errors/service-unavailable',
                'title' => 'Service Temporarily Unavailable',
                'status' => 503,
                'detail' => 'The service is temporarily unavailable due to maintenance.',
            ], 503);
        }

        // Check feature-specific kill switch
        if (config("killswitch.{$feature}")) {
            return response()->json([
                'type' => 'https://rekberkan.com/errors/feature-disabled',
                'title' => 'Feature Temporarily Disabled',
                'status' => 503,
                'detail' => "The {$feature} feature is temporarily disabled.",
            ], 503);
        }

        return $next($request);
    }
}
