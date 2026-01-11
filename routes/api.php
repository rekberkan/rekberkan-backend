<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DepositController;
use App\Http\Controllers\Api\V1\EscrowController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Health check
Route::get('/health', fn() => response()->json(['status' => 'ok']));

// V1 API
Route::prefix('v1')->group(function () {
    
    // Public routes
    Route::post('/auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:5,1'); // 5 requests per minute
    
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');
    
    Route::post('/auth/refresh', [AuthController::class, 'refresh'])
        ->middleware('throttle:10,1');
    
    // Webhooks (no auth required)
    Route::post('/webhooks/xendit/deposit', [DepositController::class, 'webhook'])
        ->middleware('throttle:100,1');
    
    // Protected routes
    Route::middleware(['auth:api', 'throttle:60,1'])->group(function () {
        
        // Auth
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        
        // Deposits
        Route::get('/deposits', [DepositController::class, 'index']);
        Route::post('/deposits', [DepositController::class, 'store'])
            ->middleware('check.killswitch:deposits');
        Route::get('/deposits/{id}', [DepositController::class, 'show']);
        
        // Escrows
        Route::get('/escrows', [EscrowController::class, 'index']);
        Route::post('/escrows', [EscrowController::class, 'store'])
            ->middleware('check.killswitch:escrow_create');
        Route::get('/escrows/{id}', [EscrowController::class, 'show']);
        Route::post('/escrows/{id}/fund', [EscrowController::class, 'fund']);
        Route::post('/escrows/{id}/delivered', [EscrowController::class, 'markDelivered']);
        Route::post('/escrows/{id}/release', [EscrowController::class, 'release'])
            ->middleware('check.killswitch:escrow_release');
        Route::post('/escrows/{id}/refund', [EscrowController::class, 'refund'])
            ->middleware('check.killswitch:escrow_refund');
        Route::post('/escrows/{id}/dispute', [EscrowController::class, 'dispute']);
    });
});
