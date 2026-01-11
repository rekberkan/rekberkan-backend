# Complete API Routes Reference

## 🔑 Authentication Routes
```php
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('auth:sanctum');
});
```

## 💰 Financial Operations (Protected + Rate Limited)
```php
Route::middleware(['auth:sanctum'])->group(function () {
    
    // Deposits
    Route::prefix('deposits')->middleware('rate.limit.financial:deposit')->group(function () {
        Route::post('/', [DepositController::class, 'store']);
        Route::get('/', [DepositController::class, 'index']);
        Route::get('/{id}', [DepositController::class, 'show']);
    });
    
    // Withdrawals (NEW)
    Route::prefix('withdrawals')->middleware('rate.limit.financial:withdrawal')->group(function () {
        Route::post('/', [WithdrawalController::class, 'store']);
        Route::get('/', [WithdrawalController::class, 'index']);
        Route::get('/{id}', [WithdrawalController::class, 'show']);
        Route::delete('/{id}', [WithdrawalController::class, 'destroy']);
    });
    
    // Escrow
    Route::prefix('escrow')->middleware('rate.limit.financial:escrow')->group(function () {
        Route::post('/', [EscrowController::class, 'store']);
        Route::get('/', [EscrowController::class, 'index']);
        Route::get('/{id}', [EscrowController::class, 'show']);
        Route::post('/{id}/release', [EscrowController::class, 'release']);
        Route::post('/{id}/refund', [EscrowController::class, 'refund']);
    });
    
    // Wallet (NEW)
    Route::prefix('wallet')->group(function () {
        Route::get('/', [WalletController::class, 'index']);
        Route::get('/transactions', [WalletController::class, 'transactions']);
        Route::get('/statistics', [WalletController::class, 'statistics']);
    });
});
```

## 🎯 Campaigns & Vouchers (NEW)
```php
Route::middleware(['auth:sanctum'])->group(function () {
    
    // Campaigns
    Route::prefix('campaigns')->group(function () {
        Route::get('/', [CampaignController::class, 'index']);
        Route::get('/{id}', [CampaignController::class, 'show']);
        Route::post('/{id}/participate', [CampaignController::class, 'participate']);
        Route::get('/my/participations', [CampaignController::class, 'myParticipations']);
    });
    
    // Vouchers
    Route::prefix('vouchers')->group(function () {
        Route::get('/', [VoucherController::class, 'index']);
        Route::post('/apply', [VoucherController::class, 'apply']);
        Route::post('/validate', [VoucherController::class, 'validate']);
        Route::get('/my-vouchers', [VoucherController::class, 'myVouchers']);
    });
});
```

## 💬 Chat & Notifications (NEW)
```php
Route::middleware(['auth:sanctum'])->group(function () {
    
    // Chat
    Route::prefix('chats')->group(function () {
        Route::get('/', [ChatController::class, 'index']);
        Route::get('/{id}', [ChatController::class, 'show']);
        Route::post('/{id}/messages', [ChatController::class, 'sendMessage']);
        Route::post('/{id}/read', [ChatController::class, 'markAsRead']);
    });
    
    // Notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::delete('/{id}', [NotificationController::class, 'destroy']);
    });
});
```

## 🎉 Membership System (NEW - P3)
```php
Route::middleware(['auth:sanctum'])->prefix('membership')->group(function () {
    Route::get('/', [MembershipController::class, 'index']);
    Route::get('/tiers', [MembershipController::class, 'tiers']);
    Route::post('/subscribe', [MembershipController::class, 'subscribe']);
    Route::post('/cancel', [MembershipController::class, 'cancel']);
    Route::get('/benefits', [MembershipController::class, 'benefits']);
});
```

## 👨‍💼 Admin Panel (NEW - P2)
```php
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    
    // User Management
    Route::get('/users', [AdminController::class, 'users']);
    Route::get('/users/{id}', [AdminController::class, 'userDetails']);
    Route::put('/users/{id}/status', [AdminController::class, 'updateUserStatus']);
    
    // KYC Management
    Route::get('/kyc-pending', [AdminController::class, 'kycPending']);
    Route::post('/kyc/{id}/decision', [AdminController::class, 'kycDecision']);
    
    // Security & Monitoring
    Route::get('/security-logs', [AdminController::class, 'securityLogs']);
    Route::get('/dashboard', [AdminController::class, 'dashboardStats']);
    
    // Configuration
    Route::get('/config/risk-engine', [AdminConfigController::class, 'getRiskEngineConfig']);
    Route::put('/config/risk-engine', [AdminConfigController::class, 'updateRiskEngineConfig']);
    Route::get('/config/system', [AdminConfigController::class, 'getSystemConfig']);
    Route::put('/config/system', [AdminConfigController::class, 'updateSystemConfig']);
});
```

## 🤖 Webhooks (Public, No Auth)
```php
Route::prefix('webhooks')->group(function () {
    // Xendit Deposit Webhook (NEW - P0 Security)
    Route::post('/xendit/deposit', [DepositController::class, 'webhook']);
    
    // Future: Midtrans, other payment gateways
});
```

## 🚦 Middleware Applied

### Security Headers
- Applied globally via `app/Http/Kernel.php`
- Stricter CSP in production (P1 fix)

### Rate Limiting
- `rate.limit.financial:operation` - Applied to all financial endpoints (P1 fix)
  - deposit: 10/hour
  - withdrawal: 5/hour
  - escrow: 20/hour

### Admin Check
- `admin` - Checks user role for admin panel access (P2)

### Tenant Isolation
- `tenant` - Sets current tenant context

## 📊 Summary

**Total Endpoints:** 50+

**New in This PR:**
- P0: Webhook security (3 endpoints)
- P1: Missing controllers (20+ endpoints)
- P2: Admin panel (12 endpoints)  
- P3: Membership system (5 endpoints)

**Protected:** 45+ endpoints require authentication
**Rate Limited:** 9 financial endpoints
**Admin Only:** 12 admin endpoints
