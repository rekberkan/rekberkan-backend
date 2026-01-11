# Security Implementation Guide

## Overview
This document outlines the comprehensive security enhancements implemented in the Rekberkan backend system.

## Implemented Security Features

### 1. CAPTCHA After Failed Login Attempts
**File**: `app/Http/Middleware/CaptchaAfterFailedLogin.php`

**Description**: Implements CAPTCHA challenge after 3 consecutive failed login attempts to mitigate credential stuffing attacks.

**Configuration**:
```env
CAPTCHA_ENABLED=true
CAPTCHA_THRESHOLD=3
RECAPTCHA_SECRET_KEY=your_key_here
```

**Usage**: Middleware automatically tracks failed login attempts and requires CAPTCHA validation when threshold is exceeded.

---

### 2. Breached Password Database Check
**File**: `app/Services/BreachedPasswordService.php`

**Description**: Integrates with HaveIBeenPwned API to detect password reuse from known data breaches using k-anonymity model.

**Configuration**:
```env
HIBP_ENABLED=true
```

**Usage**:
```php
$service = app(BreachedPasswordService::class);
if ($service->isPasswordBreached($password)) {
    // Reject password or warn user
}
```

---

### 3. Mandatory MFA for Admin Accounts
**File**: `app/Http/Middleware/RequireAdminMfa.php`

**Description**: Enforces multi-factor authentication for all admin accounts, reducing compromise risk.

**Configuration**:
```env
MFA_ADMIN_REQUIRED=true
```

**Protected Routes**: All `/api/admin/*` routes

---

### 4. Admin Panel IP Whitelist
**File**: `app/Http/Middleware/AdminIpWhitelist.php`

**Description**: Restricts admin panel access to whitelisted IP addresses or CIDR ranges.

**Configuration**:
```env
ADMIN_IP_WHITELIST=203.0.113.1,198.51.100.0/24
```

**Features**:
- Supports individual IPs
- Supports CIDR notation
- Proxy-aware (X-Forwarded-For)

---

### 5. Payment Gateway Callback IP Whitelist
**File**: `app/Http/Middleware/PaymentCallbackIpWhitelist.php`

**Description**: Prevents webhook spoofing by validating payment gateway IP addresses.

**Supported Gateways**:
- Midtrans (103.127.16.0/23, 103.208.23.0/24)
- Xendit (18.141.95.53, 54.255.215.155, etc.)

**Usage**: Apply to webhook routes
```php
Route::post('/webhooks/midtrans', [WebhookController::class, 'midtrans'])
    ->middleware('payment-callback:midtrans');
```

---

### 6. SQLMap Automated Scanning
**File**: `.github/workflows/sqlmap-scan.yml`

**Description**: Automated SQL injection vulnerability scanning in CI/CD pipeline.

**Schedule**: 
- On every push/PR to main/develop
- Weekly on Sundays at 2 AM WIB

**Scanned Endpoints**:
- Authentication endpoints
- Transaction queries
- User search functionality

---

### 7. Tenant Isolation Bypass Tests
**File**: `tests/Feature/Security/TenantIsolationTest.php`

**Description**: Comprehensive security tests to ensure proper multi-tenant data isolation.

**Test Coverage**:
- Cross-tenant data access attempts
- SQL injection bypass attempts
- Tenant ID manipulation
- Header manipulation attacks

**Run Tests**:
```bash
php artisan test --filter=TenantIsolationTest
```

---

### 8. Auto-Scaling Configuration
**Files**: 
- `docker-compose.autoscale.yml`
- `k8s/deployment.yml`
- `k8s/hpa.yml`

**Description**: Kubernetes Horizontal Pod Autoscaler (HPA) configuration for handling traffic spikes and DDoS mitigation.

**Configuration**:
- Min replicas: 3
- Max replicas: 20
- CPU threshold: 70%
- Memory threshold: 80%

**Deploy**:
```bash
kubectl apply -f k8s/deployment.yml
kubectl apply -f k8s/hpa.yml
```

---

### 9. Geo-Blocking for Non-Indonesian IPs
**File**: `app/Http/Middleware/GeoBlocking.php`

**Description**: Restricts access to Indonesian IP addresses only, reducing attack surface.

**Configuration**:
```env
GEO_BLOCKING_ENABLED=true
```

**Features**:
- IP geolocation via ip-api.com
- Cloudflare CF-IPCountry header support
- Fallback to Indonesian ISP range detection
- 24-hour caching for performance

---

### 10. Query Performance Testing in CI
**File**: `.github/workflows/query-performance.yml`

**Description**: Automated query performance regression testing.

**Features**:
- Tests common database queries
- Identifies queries exceeding 100ms threshold
- Generates performance report artifacts

**Commands**:
```bash
php artisan performance:analyze-queries
php artisan performance:report --output=report.html
```

---

## Installation

### 1. Update Environment Variables
Copy security configuration:
```bash
cat .env.security.example >> .env
```

### 2. Register Middleware
Add to `app/Http/Kernel.php`:
```php
protected $middlewareGroups = [
    'api' => [
        // ... existing middleware
        \App\Http\Middleware\GeoBlocking::class,
        \App\Http\Middleware\CaptchaAfterFailedLogin::class,
    ],
];

protected $routeMiddleware = [
    // ... existing middleware
    'admin.ip' => \App\Http\Middleware\AdminIpWhitelist::class,
    'admin.mfa' => \App\Http\Middleware\RequireAdminMfa::class,
    'payment-callback' => \App\Http\Middleware\PaymentCallbackIpWhitelist::class,
];
```

### 3. Apply Admin Protection
Update admin routes:
```php
Route::middleware(['auth:api', 'admin.ip', 'admin.mfa'])->prefix('admin')->group(function () {
    // Admin routes here
});
```

### 4. Install reCAPTCHA Package
```bash
composer require google/recaptcha
```

### 5. Run Tests
```bash
php artisan test
```

## Monitoring

### Check Security Logs
```bash
tail -f storage/logs/laravel.log | grep -i "blocked\|denied\|unauthorized"
```

### Performance Monitoring
```bash
php artisan performance:analyze-queries
```

## Maintenance

### Update IP Whitelists
Edit `.env` and restart application:
```bash
ADMIN_IP_WHITELIST=203.0.113.1,198.51.100.0/24
php artisan config:cache
```

### Review Security Alerts
Check GitHub Actions for SQLMap scan results and performance regressions.

## Support

For security vulnerabilities, please see `SECURITY.md` for responsible disclosure procedures.
