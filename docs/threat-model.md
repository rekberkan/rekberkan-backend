# Threat Model

## Overview
This document identifies potential threats to Rekberkan platform, their impact, likelihood, and mitigations. Updated: January 2026.

## Threat Categories

### 1. Authentication & Authorization Threats

#### T-AUTH-001: Credential Stuffing
**Description**: Attacker uses leaked credentials from other breaches to access user accounts.

**Attack Vector**:
- Automated login attempts with username/password pairs from public dumps
- Targets users who reuse passwords across sites

**Impact**: HIGH
- Unauthorized wallet access
- Fraudulent withdrawals
- Reputation damage

**Likelihood**: HIGH (common attack)

**Mitigations**:
- ✅ Rate limiting: 5 failed attempts → 15-minute lockout
- ✅ Email notification on new device login
- ✅ Risk engine flags rapid device/IP changes
- ⚠️ TODO: Implement CAPTCHA after 3 failed attempts
- ⚠️ TODO: Breach database check (HaveIBeenPwned API)

**Residual Risk**: MEDIUM

---

#### T-AUTH-002: JWT Token Theft
**Description**: Attacker steals JWT token via XSS or local storage compromise.

**Attack Vector**:
- XSS vulnerability in frontend
- Malware on user's device
- Unencrypted token transmission

**Impact**: CRITICAL
- Full account takeover
- Unauthorized financial operations

**Likelihood**: MEDIUM

**Mitigations**:
- ✅ HTTPOnly cookies (not localStorage)
- ✅ Short expiration (1 hour)
- ✅ TLS enforcement via HSTS
- ✅ CSP headers prevent inline scripts
- ✅ Device fingerprinting for session binding

**Residual Risk**: LOW

---

#### T-AUTH-003: Admin Account Compromise
**Description**: Attacker gains access to admin panel.

**Attack Vector**:
- Phishing for admin credentials
- Weak admin passwords
- Missing MFA

**Impact**: CRITICAL
- Unauthorized dispute resolutions
- Manual ledger adjustments
- Access to all tenant data

**Likelihood**: MEDIUM

**Mitigations**:
- ✅ Separate admin authentication system
- ✅ Step-up authentication for sensitive ops
- ✅ Maker-checker for dispute actions
- ✅ Admin actions logged to immutable audit log
- ⚠️ TODO: Mandatory MFA (TOTP/U2F)
- ⚠️ TODO: IP whitelist for admin access

**Residual Risk**: MEDIUM

---

### 2. Financial Threats

#### T-FIN-001: Double Withdrawal
**Description**: Attacker exploits race condition to withdraw same funds twice.

**Attack Vector**:
- Concurrent API requests
- Replay attacks
- Idempotency key bypass

**Impact**: CRITICAL
- Direct financial loss
- Ledger inconsistency
- Insolvency risk

**Likelihood**: LOW (if mitigations in place)

**Mitigations**:
- ✅ Database-level locking (SELECT FOR UPDATE)
- ✅ Idempotency keys required
- ✅ Post-execution balance checks
- ✅ Daily reconciliation
- ✅ Withdrawal rate limiting (5/hour)

**Residual Risk**: LOW

---

#### T-FIN-002: Ledger Tampering
**Description**: Attacker modifies ledger entries to hide fraud.

**Attack Vector**:
- SQL injection
- Compromised database credentials
- Insider threat

**Impact**: CRITICAL
- Undetected theft
- Compliance violation
- Loss of audit trail

**Likelihood**: LOW

**Mitigations**:
- ✅ Ledger table immutable (DB triggers)
- ✅ Hash-chain audit log
- ✅ Daily integrity verification
- ✅ RLS policies prevent cross-tenant access
- ✅ Parameterized queries (no raw SQL)

**Residual Risk**: VERY LOW

---

#### T-FIN-003: Fake Deposit Confirmation
**Description**: Attacker forges payment gateway webhook to credit wallet without payment.

**Attack Vector**:
- Unsigned webhook
- Weak signature verification
- Replay attack

**Impact**: CRITICAL
- Free money creation
- Platform insolvency

**Likelihood**: LOW

**Mitigations**:
- ✅ Webhook signature verification (HMAC-SHA256)
- ✅ Idempotency on transaction_id
- ✅ Manual reconciliation against gateway dashboard
- ✅ Anomaly detection (unusual deposit patterns)
- ⚠️ TODO: Callback IP whitelist

**Residual Risk**: LOW

---

### 3. Data Breach Threats

#### T-DATA-001: SQL Injection
**Description**: Attacker injects malicious SQL to extract data.

**Attack Vector**:
- Unsanitized user input
- Raw SQL queries
- Second-order injection

**Impact**: CRITICAL
- Full database dump
- PII exposure
- Financial data leak

**Likelihood**: LOW (if proper ORM usage)

**Mitigations**:
- ✅ Laravel Eloquent ORM (parameterized)
- ✅ Input validation
- ✅ RLS policies limit blast radius
- ✅ Database user with minimal privileges
- ⚠️ TODO: Regular SQLMap scans in CI

**Residual Risk**: VERY LOW

---

#### T-DATA-002: Tenant Isolation Bypass
**Description**: User accesses another tenant's data.

**Attack Vector**:
- Missing tenant_id check
- JWT tenant_id manipulation
- RLS policy misconfiguration

**Impact**: CRITICAL
- Cross-tenant data leak
- Competitor intelligence
- Regulatory violation

**Likelihood**: MEDIUM

**Mitigations**:
- ✅ RLS policies on all tables
- ✅ Middleware sets tenant context
- ✅ JWT signature prevents tampering
- ✅ Automated tests verify isolation
- ⚠️ TODO: Penetration test specifically for tenant bypass

**Residual Risk**: LOW

---

### 4. Availability Threats

#### T-AVAIL-001: DDoS Attack
**Description**: Attacker floods API to cause service outage.

**Attack Vector**:
- HTTP flood
- Slowloris
- Application-layer attacks

**Impact**: HIGH
- Service unavailable
- Revenue loss
- Reputation damage

**Likelihood**: HIGH

**Mitigations**:
- ✅ Rate limiting per IP/user
- ✅ Cloudflare/AWS Shield protection
- ⚠️ TODO: Auto-scaling on load spike
- ⚠️ TODO: Geo-blocking for non-Indonesian IPs

**Residual Risk**: MEDIUM

---

#### T-AVAIL-002: Resource Exhaustion
**Description**: Expensive queries or infinite loops exhaust resources.

**Attack Vector**:
- Unoptimized database queries
- Missing pagination
- Memory leaks

**Impact**: HIGH
- Slow response times
- Database connection pool exhaustion

**Likelihood**: MEDIUM

**Mitigations**:
- ✅ Query timeout enforcement
- ✅ Pagination on list endpoints
- ✅ Database query logging
- ✅ Horizon for queue monitoring
- ⚠️ TODO: Query performance testing in CI

**Residual Risk**: LOW

---

### 5. Insider Threats

#### T-INSIDER-001: Malicious Admin
**Description**: Admin abuses privileges for personal gain.

**Attack Vector**:
- Unauthorized dispute resolution
- Manual ledger adjustment
- Data exfiltration

**Impact**: CRITICAL
- Financial fraud
- Data breach
- Regulatory violation

**Likelihood**: LOW

**Mitigations**:
- ✅ Maker-checker for sensitive ops
- ✅ Step-up authentication
- ✅ All actions logged to immutable audit
- ✅ Separation of duties
- ⚠️ TODO: Anomaly detection on admin behavior
- ⚠️ TODO: Quarterly access review

**Residual Risk**: MEDIUM

---

### 6. Application Logic Threats

#### T-LOGIC-001: Escrow State Manipulation
**Description**: Attacker forces invalid escrow state transitions.

**Attack Vector**:
- API race conditions
- Missing state validation
- Direct database manipulation

**Impact**: HIGH
- Funds released prematurely
- Buyer/seller fraud

**Likelihood**: LOW

**Mitigations**:
- ✅ State machine with explicit transitions
- ✅ Database constraints on valid states
- ✅ Pessimistic locking
- ✅ Comprehensive state transition tests

**Residual Risk**: VERY LOW

---

## Threat Matrix

| Threat ID | Category | Impact | Likelihood | Residual Risk | Priority |
|-----------|----------|--------|------------|---------------|----------|
| T-AUTH-003 | Auth | CRITICAL | MEDIUM | MEDIUM | P0 (MFA) |
| T-INSIDER-001 | Insider | CRITICAL | LOW | MEDIUM | P1 |
| T-AVAIL-001 | Availability | HIGH | HIGH | MEDIUM | P1 |
| T-AUTH-001 | Auth | HIGH | HIGH | MEDIUM | P2 |
| T-FIN-003 | Financial | CRITICAL | LOW | LOW | P2 |
| T-DATA-002 | Data | CRITICAL | MEDIUM | LOW | P3 |

## Action Items

### P0 (Immediate)
1. Implement mandatory MFA for admin accounts

### P1 (Next Sprint)
1. Admin behavior anomaly detection
2. Auto-scaling configuration
3. IP whitelist for admin panel

### P2 (Next Quarter)
1. CAPTCHA on failed logins
2. Breach database integration
3. Payment gateway IP whitelist
4. Geo-blocking

### P3 (Ongoing)
1. Quarterly penetration testing
2. SQLMap scanning in CI/CD
3. Query performance regression tests

## Review Schedule

Threat model reviewed:
- After any security incident
- Quarterly with security team
- Before major feature releases

**Last Review**: January 11, 2026  
**Next Review**: April 11, 2026
