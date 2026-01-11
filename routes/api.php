<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Health check endpoints (no authentication required)
Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');

// API routes requiring authentication
Route::middleware(['auth:api'])->group(function () {
    // Add authenticated routes here
});
