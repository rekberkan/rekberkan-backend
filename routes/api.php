<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DepositController;
use App\Http\Controllers\Api\V1\EscrowController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\VoucherController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\MembershipController;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\Admin\AdminConfigController;
use App\Http\Middleware\CheckAdminRole;
use App\Http\Middleware\VerifyXenditWebhook;
use App\Http\Middleware\VerifyMidtransWebhook;
use Illuminate\Support\Facades\Route;

// Health check endpoints (no authentication required)
Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');

// API routes
Route::prefix('v1')->group(function () {
    // Auth routes with rate limiting
    Route::middleware(['throttle:auth'])->group(function () {
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/login', [AuthController::class, 'login']);
        Route::post('auth/refresh', [AuthController::class, 'refresh']);
    });

    // Webhook routes with signature verification (CRITICAL FIX: Bug #1)
    Route::prefix('webhooks')->group(function () {
        Route::post('xendit', [DepositController::class, 'webhook'])
            ->middleware(VerifyXenditWebhook::class)
            ->name('webhooks.xendit');

        Route::post('midtrans', [WebhookController::class, 'midtransNotification'])
            ->middleware(VerifyMidtransWebhook::class)
            ->name('webhooks.midtrans');
    });

    // Authenticated routes
    Route::middleware(['auth:api'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        // Deposit routes with rate limiting (FIX: Bug #7)
        Route::middleware(['throttle:deposit'])->group(function () {
            Route::get('deposits', [DepositController::class, 'index']);
            Route::post('deposits', [DepositController::class, 'store']);
            Route::get('deposits/{id}', [DepositController::class, 'show']);
        });

        // Escrow routes with rate limiting (FIX: Bug #7)
        Route::middleware(['throttle:escrow'])->group(function () {
            Route::get('escrows', [EscrowController::class, 'index']);
            Route::post('escrows', [EscrowController::class, 'store']);
            Route::get('escrows/{id}', [EscrowController::class, 'show']);
            Route::post('escrows/{id}/fund', [EscrowController::class, 'fund']);
            Route::post('escrows/{id}/deliver', [EscrowController::class, 'markDelivered']);
            Route::post('escrows/{id}/release', [EscrowController::class, 'release']);
            Route::post('escrows/{id}/refund', [EscrowController::class, 'refund']);
            Route::post('escrows/{id}/dispute', [EscrowController::class, 'dispute']);
        });

        Route::get('campaigns', [CampaignController::class, 'index']);
        Route::get('campaigns/{id}', [CampaignController::class, 'show']);
        Route::post('campaigns/{id}/participate', [CampaignController::class, 'participate']);
        Route::get('campaigns/participations/me', [CampaignController::class, 'myParticipations']);

        Route::get('vouchers', [VoucherController::class, 'index']);
        Route::post('vouchers/apply', [VoucherController::class, 'apply']);
        Route::get('vouchers/me', [VoucherController::class, 'myVouchers']);
        Route::post('vouchers/validate', [VoucherController::class, 'validate']);

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::delete('notifications/{id}', [NotificationController::class, 'destroy']);

        Route::get('chats', [ChatController::class, 'index']);
        Route::get('chats/{chatId}', [ChatController::class, 'show']);
        Route::post('chats/{chatId}/messages', [ChatController::class, 'sendMessage']);
        Route::post('chats/{chatId}/read', [ChatController::class, 'markAsRead']);

        Route::get('memberships', [MembershipController::class, 'index']);
        Route::get('memberships/tiers', [MembershipController::class, 'tiers']);
        Route::post('memberships/subscribe', [MembershipController::class, 'subscribe']);
        Route::post('memberships/cancel', [MembershipController::class, 'cancel']);
        Route::get('memberships/benefits', [MembershipController::class, 'benefits']);

        // Admin routes
        Route::prefix('admin')
            ->middleware([CheckAdminRole::class, 'throttle:admin'])
            ->group(function () {
                Route::get('users', [AdminController::class, 'users']);
                Route::get('users/{userId}', [AdminController::class, 'userDetails']);
                Route::patch('users/{userId}/status', [AdminController::class, 'updateUserStatus']);
                Route::get('kyc/pending', [AdminController::class, 'kycPending']);
                Route::post('kyc/{kycId}/decision', [AdminController::class, 'kycDecision']);
                Route::get('security/logs', [AdminController::class, 'securityLogs']);
                Route::get('configs/risk', [AdminConfigController::class, 'getRiskEngineConfig']);
                Route::put('configs/risk', [AdminConfigController::class, 'updateRiskEngineConfig']);
                Route::get('configs/system', [AdminConfigController::class, 'getSystemConfig']);
                Route::put('configs/system', [AdminConfigController::class, 'updateSystemConfig']);
            });
    });
});
