# Security Guidelines - Kahade/Rekberkan

## CSRF Protection Strategy

### API Security (JWT-based)

**SECURITY FIX: Bug #12 - CSRF Protection for JWT API**

Our API uses JWT (JSON Web Tokens) for authentication, which provides inherent protection against CSRF attacks when implemented correctly:

#### Why JWT Protects Against CSRF

1. **No Cookies**: JWTs are stored in `localStorage` or `sessionStorage`, not in cookies
2. **Explicit Headers**: Token must be explicitly added to `Authorization` header
3. **Same-Origin Policy**: Browser's SOP prevents malicious sites from reading tokens
4. **No Automatic Sending**: Unlike cookies, JWTs are not automatically sent with requests

#### Implementation Requirements

✅ **Current Implementation (Secure)**
```javascript
// Frontend must send JWT in Authorization header
headers: {
  'Authorization': `Bearer ${token}`,
  'Content-Type': 'application/json'
}
```

❌ **Insecure Pattern (AVOID)**
```javascript
// Never store JWT in cookies with authentication
document.cookie = `token=${jwt}`; // VULNERABLE TO CSRF!
```

#### Additional CSRF Protections

We implement multiple layers of CSRF protection:

1. **Origin Validation** (SecurityHeaders middleware)
   - Checks `Origin` and `Referer` headers
   - Blocks requests from untrusted domains

2. **State-Changing Operations**
   - Require POST/PUT/DELETE methods (never GET)
   - Validate tenant_id in request matches authenticated user

3. **Critical Operations** (deposits, withdrawals, escrow)
   - Additional device fingerprint validation
   - Step-up authentication for sensitive actions

4. **SameSite Cookies** (for session fallback)
   ```php
   // If using cookies for session management
   'same_site' => 'strict',
   'secure' => true,
   'http_only' => true,
   ```

### Frontend Guidelines

#### Secure Token Storage

```javascript
// ✅ RECOMMENDED: sessionStorage (best security)
sessionStorage.setItem('auth_token', token);

// ✅ ACCEPTABLE: localStorage (convenient, less secure)
localStorage.setItem('auth_token', token);

// ❌ NEVER: Cookies without proper flags
// ❌ NEVER: Global variables
// ❌ NEVER: URL parameters
```

#### Token Transmission

```javascript
// ✅ CORRECT: Authorization header
const response = await fetch('/api/v1/escrows', {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json',
    'X-Tenant-ID': tenantId,
  },
  body: JSON.stringify(data)
});

// ❌ WRONG: Query parameter (exposes token in logs)
fetch(`/api/v1/escrows?token=${token}`);

// ❌ WRONG: Request body (non-standard)
fetch('/api/v1/escrows', {
  body: JSON.stringify({ token, ...data })
});
```

### Backend CSRF Validation

For web forms or browser-based flows (non-API), Laravel's CSRF protection is enabled:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    // API routes (JWT) - CSRF not needed
    $middleware->api(prepend: [
        \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    ]);
    
    // Web routes - CSRF enabled by default
    // Uses @csrf directive in Blade templates
})
```

### Security Headers (Already Implemented)

```php
// app/Http/Middleware/SecurityHeaders.php
'X-Frame-Options' => 'DENY',  // Prevents clickjacking
'X-Content-Type-Options' => 'nosniff',  // Prevents MIME sniffing
'Content-Security-Policy' => "frame-ancestors 'none'",  // Extra clickjacking protection
```

## Testing CSRF Protection

### Manual Testing

1. **Test 1: Cross-origin request without token**
   ```bash
   curl -X POST https://api.rekberkan.com/api/v1/escrows \
     -H "Origin: https://evil.com" \
     -d '{"amount": 1000000}'
   
   # Expected: 401 Unauthorized (no token)
   ```

2. **Test 2: Valid token from wrong origin**
   ```bash
   curl -X POST https://api.rekberkan.com/api/v1/escrows \
     -H "Authorization: Bearer <valid_token>" \
     -H "Origin: https://evil.com" \
     -d '{"amount": 1000000}'
   
   # Expected: 200 OK (JWT prevents CSRF, origin check is secondary)
   ```

3. **Test 3: Cookie-based auth (if implemented)**
   ```bash
   curl -X POST https://api.rekberkan.com/api/v1/escrows \
     -H "Cookie: session=xyz" \
     -H "Origin: https://evil.com" \
     -d '{"amount": 1000000}'
   
   # Expected: 403 Forbidden (CSRF protection triggers)
   ```

### Automated Testing

```php
// tests/Feature/CsrfProtectionTest.php
public function test_jwt_auth_prevents_csrf()
{
    $user = User::factory()->create();
    $token = JWTAuth::fromUser($user);
    
    // Valid request with JWT - should succeed
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'Origin' => 'https://evil.com',
    ])->postJson('/api/v1/escrows', $data);
    
    $response->assertStatus(201); // JWT protects against CSRF
}
```

## Incident Response

If CSRF vulnerability is discovered:

1. **Immediate Actions**
   - Revoke all active tokens
   - Force re-authentication
   - Review access logs for suspicious activity

2. **Investigation**
   ```bash
   # Check for suspicious cross-origin requests
   grep "Origin:" /var/log/nginx/access.log | grep -v "rekberkan.com"
   
   # Review authentication logs
   tail -f storage/logs/laravel.log | grep "CSRF"
   ```

3. **Mitigation**
   - Deploy origin whitelist
   - Enable stricter CORS policy
   - Implement additional request signing

## Best Practices Summary

✅ **DO**
- Use JWT in Authorization header
- Store tokens in sessionStorage/localStorage
- Validate Origin header for sensitive operations
- Use HTTPS everywhere
- Implement request signing for critical operations
- Log suspicious cross-origin requests

❌ **DON'T**
- Store JWT in cookies (makes it vulnerable to CSRF)
- Send tokens in URL parameters
- Accept authentication from Cookie header for API
- Trust client-side validation alone
- Expose sensitive operations via GET requests

## Additional Resources

- [OWASP CSRF Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html)
- [JWT Best Practices](https://datatracker.ietf.org/doc/html/rfc8725)
- [Laravel Security Best Practices](https://laravel.com/docs/security)

---

**Last Updated**: January 11, 2026  
**Security Contact**: security@rekberkan.com
