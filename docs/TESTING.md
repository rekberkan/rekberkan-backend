# Testing Guide - Bug Fixes

## 📝 Overview
Guide lengkap untuk testing semua bug fixes dari P0 hingga P3.

---

## P0: Critical Security Fixes

### 1. 🔐 Audit Hash Calculation

**Test Hash Integrity:**
```bash
php artisan tinker

# Create audit log
$service = app(\App\Services\AuditService::class);
$log = $service->log([
    'tenant_id' => 1,
    'event_type' => 'test.event',
    'actor_id' => 1,
    'metadata' => ['test' => 'data']
]);

# Verify chain integrity
$result = $service->verifyChainIntegrity(1);
dd($result);

# Expected: integrity_valid = true
```

**Test Tampering Detection:**
```sql
-- Manually tamper with data
UPDATE audit_logs SET actor_id = 999 WHERE id = 1;
```

```bash
php artisan tinker
$result = app(\App\Services\AuditService::class)->verifyChainIntegrity(1);
dd($result);

# Expected: integrity_valid = false, dengan detail issue
```

---

### 2. 🌍 GeoIP Integration

**Test GeoIP Resolution:**
```bash
# Test dengan IP publik
curl -H "X-Forwarded-For: 8.8.8.8" \
     -H "Authorization: Bearer YOUR_TOKEN" \
     https://your-api.com/api/v1/wallet

# Check security_events table
SELECT ip_address, country_code, event_type 
FROM security_events 
WHERE ip_address = '8.8.8.8'
ORDER BY created_at DESC
LIMIT 1;

# Expected: country_code = 'US'
```

**Test Caching:**
```bash
php artisan tinker

# First call (hits API)
$ip = '8.8.8.8';
Cache::forget('geoip:' . $ip);
$start = microtime(true);
// Make request
$time1 = microtime(true) - $start;

# Second call (from cache)
$start = microtime(true);
// Make same request
$time2 = microtime(true) - $start;

echo "First: {$time1}s, Second: {$time2}s";
# Expected: Second call significantly faster
```

---

### 3. ⚠️ Webhook Security

**Setup:**
```bash
# Add to .env
XENDIT_WEBHOOK_SECRET=test_secret_key
XENDIT_VERIFY_IP=false  # For testing
```

**Test Timestamp Validation:**
```bash
# Expired timestamp (> 5 minutes old)
curl -X POST https://your-api.com/api/webhooks/xendit/deposit \
  -H "Content-Type: application/json" \
  -H "x-timestamp: 2024-01-01T00:00:00Z" \
  -H "x-callback-token: SIGNATURE" \
  -d '{"id": "test-123", "amount": 100000}'

# Expected: 400 Bad Request
# Response: "Webhook timestamp expired or invalid"
```

**Test Valid Webhook:**
```bash
# Generate valid signature
php artisan tinker

$payload = ['id' => 'test-123', 'amount' => 100000];
$secret = config('payment.xendit.webhook_secret');
$signature = hash_hmac('sha256', json_encode($payload), $secret);
echo $signature;
```

```bash
# Send valid webhook
curl -X POST https://your-api.com/api/webhooks/xendit/deposit \
  -H "Content-Type: application/json" \
  -H "x-timestamp: $(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  -H "x-callback-token: SIGNATURE_FROM_ABOVE" \
  -d '{"id": "test-123", "amount": 100000}'

# Expected: 200 OK
# Check deposits table for new record
```

**Test Idempotency:**
```bash
# Send same webhook twice
# Expected: Second request returns success but doesn't duplicate
```

---

## P1: Missing Controllers & Features

### 4. 📊 Rate Limiting

**Test Financial Rate Limits:**
```bash
# Deposit rate limit (10/hour)
for i in {1..12}; do
  curl -X POST https://your-api.com/api/v1/deposits \
    -H "Authorization: Bearer YOUR_TOKEN" \
    -d '{"amount": 100000}'
  echo "Request $i"
done

# Expected: Requests 1-10 succeed
# Request 11: 429 Too Many Requests
# Response includes: "retry_after" header
```

**Test Rate Limit Headers:**
```bash
curl -v -X POST https://your-api.com/api/v1/deposits \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{"amount": 100000}'

# Expected headers:
# X-RateLimit-Limit: 10
# X-RateLimit-Remaining: 9
```

---

### 5. 🎯 Campaign & Voucher Controllers

**Test Campaign Participation:**
```bash
# List campaigns
curl https://your-api.com/api/v1/campaigns \
  -H "Authorization: Bearer YOUR_TOKEN"

# Participate in campaign
curl -X POST https://your-api.com/api/v1/campaigns/1/participate \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{"wallet_address": "0x123..."}'

# Expected: 201 Created
```

**Test Voucher Application:**
```bash
# Validate voucher
curl -X POST https://your-api.com/api/v1/vouchers/validate \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{"code": "PROMO10", "amount": 100000}'

# Apply voucher
curl -X POST https://your-api.com/api/v1/vouchers/apply \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "code": "PROMO10",
    "transaction_type": "escrow",
    "amount": 100000
  }'

# Expected: Discounted amount in response
```

---

## P2: Admin Panel

### 6. 👨‍💼 Admin Endpoints

**Setup Admin User:**
```sql
UPDATE users SET role = 'admin' WHERE email = 'admin@rekberkan.com';
-- OR
UPDATE users SET is_admin = true WHERE email = 'admin@rekberkan.com';
```

**Test User Management:**
```bash
# List users
curl https://your-api.com/api/v1/admin/users \
  -H "Authorization: Bearer ADMIN_TOKEN"

# Update user status
curl -X PUT https://your-api.com/api/v1/admin/users/123/status \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -d '{"status": "suspended", "reason": "Suspicious activity"}'
```

**Test KYC Management:**
```bash
# List pending KYC
curl https://your-api.com/api/v1/admin/kyc-pending \
  -H "Authorization: Bearer ADMIN_TOKEN"

# Approve KYC
curl -X POST https://your-api.com/api/v1/admin/kyc/1/decision \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -d '{"action": "approve", "notes": "Documents verified"}'
```

**Test Risk Engine Config:**
```bash
# Get config
curl https://your-api.com/api/v1/admin/config/risk-engine \
  -H "Authorization: Bearer ADMIN_TOKEN"

# Update config
curl -X PUT https://your-api.com/api/v1/admin/config/risk-engine \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -d '{
    "auto_block_threshold": 95,
    "manual_review_threshold": 75
  }'
```

**Test Authorization:**
```bash
# Try admin endpoint with regular user token
curl https://your-api.com/api/v1/admin/users \
  -H "Authorization: Bearer USER_TOKEN"

# Expected: 403 Forbidden
```

---

## P3: Membership System

### 7. 🎉 Membership Features

**Test Membership Tiers:**
```bash
# Get available tiers
curl https://your-api.com/api/v1/membership/tiers \
  -H "Authorization: Bearer YOUR_TOKEN"

# Expected: List of 5 tiers (free, bronze, silver, gold, platinum)
```

**Test Subscription:**
```bash
# Subscribe to Bronze
curl -X POST https://your-api.com/api/v1/membership/subscribe \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{"tier": "bronze", "payment_method": "wallet"}'

# Expected: 201 Created
# Check memberships table
```

**Test Upgrade:**
```bash
# Upgrade from Bronze to Silver
curl -X POST https://your-api.com/api/v1/membership/subscribe \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{"tier": "silver", "payment_method": "wallet"}'

# Expected: Prorated charge, tier updated
```

**Test Benefits:**
```bash
# Get current benefits
curl https://your-api.com/api/v1/membership/benefits \
  -H "Authorization: Bearer YOUR_TOKEN"

# Expected: Benefits for current tier
```

**Test Auto-Renewal:**
```bash
# Simulate expiring membership
php artisan tinker

$membership = \App\Models\Membership::first();
$membership->expires_at = now()->addHours(12);
$membership->save();
```

```bash
# Run renewal command
php artisan memberships:renew

# Expected: Membership renewed, expires_at extended
```

**Test Cancellation:**
```bash
curl -X POST https://your-api.com/api/v1/membership/cancel \
  -H "Authorization: Bearer YOUR_TOKEN"

# Expected: auto_renew set to false
# Benefits remain until expires_at
```

---

## 🧪 Database Migrations

**Run New Migrations:**
```bash
php artisan migrate

# Expected: memberships table created
```

**Rollback Test:**
```bash
php artisan migrate:rollback --step=1
php artisan migrate
```

---

## 💡 Performance Testing

**GeoIP Cache Performance:**
```bash
# Benchmark with ab (Apache Bench)
ab -n 100 -c 10 \
  -H "Authorization: Bearer TOKEN" \
  -H "X-Forwarded-For: 8.8.8.8" \
  https://your-api.com/api/v1/wallet

# Monitor cache hit rate
php artisan tinker
Cache::get('geoip:8.8.8.8'); // Should be cached
```

---

## ✅ Checklist

### P0 (Critical)
- [ ] Audit hash includes all fields
- [ ] Hash tampering detected
- [ ] GeoIP resolves correctly
- [ ] GeoIP caching works
- [ ] Webhook timestamp validation
- [ ] Webhook signature verification
- [ ] Webhook idempotency

### P1 (High)
- [ ] Rate limiting enforced
- [ ] Rate limit headers present
- [ ] All new controllers accessible
- [ ] Campaign participation works
- [ ] Voucher application works
- [ ] Chat messaging works
- [ ] Notifications work

### P2 (Medium)
- [ ] Admin user management
- [ ] KYC approval/rejection
- [ ] Security logs accessible
- [ ] Risk engine config update
- [ ] Admin authorization enforced
- [ ] Type consistency (no errors)

### P3 (Low)
- [ ] Membership subscription
- [ ] Membership upgrade
- [ ] Membership cancellation
- [ ] Benefits calculation
- [ ] Auto-renewal command
- [ ] Migration successful

---

## 🔧 Troubleshooting

**GeoIP not working:**
- Check internet connection
- Verify ipapi.co is accessible
- Check cache: `php artisan cache:clear`

**Rate limiting not working:**
- Verify middleware in `routes/api.php`
- Clear route cache: `php artisan route:clear`
- Check Redis connection

**Admin endpoints 403:**
- Verify user has admin role
- Check `CheckAdminRole` middleware
- Review `isAdmin()` logic

**Membership errors:**
- Run migrations: `php artisan migrate`
- Check memberships table exists
- Verify payment processing
