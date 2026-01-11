# Security Assumptions

## Overview
This document outlines the security assumptions upon which Rekberkan's security model is built. Understanding these assumptions is critical for proper deployment, operation, and risk assessment.

## Infrastructure Assumptions

### 1. Database Security
**Assumption**: PostgreSQL database is properly secured and isolated.

**Requirements**:
- Database server accessible only via private network or VPN
- Strong authentication (no default passwords)
- TLS 1.3 enforced for all connections
- Regular security patches applied
- Row-Level Security (RLS) policies active
- Backup encryption enabled
- TimescaleDB extension installed for hypertable support

**Risk if violated**: Complete data breach, financial fraud, tenant isolation bypass

### 2. Redis Security
**Assumption**: Redis instance is protected and not publicly accessible.

**Requirements**:
- Redis password authentication enabled
- Bind to localhost or private network only
- No `FLUSHALL` or `FLUSHDB` commands in production
- TLS encryption for connections

**Risk if violated**: Session hijacking, cache poisoning, DoS via cache flush

### 3. Storage Security
**Assumption**: File storage (S3/MinIO) enforces access controls.

**Requirements**:
- Bucket policies restrict public access
- Signed URLs with expiration for temporary access
- Encryption at rest and in transit
- Versioning enabled for audit trail

**Risk if violated**: Unauthorized access to KYC documents, evidence files, receipts

## Application Assumptions

### 4. JWT Secret Security
**Assumption**: JWT signing keys are cryptographically strong and properly rotated.

**Requirements**:
- Minimum 256-bit entropy
- Stored in environment variables, not version control
- Rotated every 90 days
- Old keys retained for 24h to allow graceful transition

**Risk if violated**: Token forgery, unauthorized API access, privilege escalation

### 5. Tenant Isolation
**Assumption**: All database queries enforce tenant context via RLS.

**Requirements**:
- `app.current_tenant_id` set for every request
- RLS policies on all tenant-scoped tables
- No raw SQL bypassing RLS
- Middleware enforces tenant ID from JWT

**Risk if violated**: Cross-tenant data leakage, unauthorized access to escrows/wallets

### 6. Admin Authentication
**Assumption**: Admin accounts use separate authentication system with MFA.

**Requirements**:
- Admin login separate from user login
- MFA (TOTP or hardware token) mandatory
- Step-up authentication for sensitive operations
- Admin sessions expire after 30 minutes of inactivity

**Risk if violated**: Admin account takeover, unauthorized dispute resolutions, financial manipulation

## Cryptographic Assumptions

### 7. Hash Function Strength
**Assumption**: SHA-256 is collision-resistant for audit hashing.

**Requirements**:
- Use SHA-256 or stronger (SHA-384, SHA-512)
- Never truncate hashes
- Store full 64-character hex output

**Risk if violated**: Audit chain tampering undetected, dispute payload forgery

### 8. Password Hashing
**Assumption**: Bcrypt with cost factor 12+ is computationally expensive.

**Requirements**:
- Bcrypt cost factor ≥ 12
- No custom implementations
- Use Laravel's `Hash` facade
- Consider migrating to Argon2id

**Risk if violated**: Password cracking via brute force

## Network Assumptions

### 9. TLS/HTTPS Enforcement
**Assumption**: All traffic is encrypted in transit.

**Requirements**:
- TLS 1.3 minimum
- HSTS header with `includeSubDomains` and `preload`
- Valid certificate from trusted CA
- HTTP redirects to HTTPS

**Risk if violated**: Man-in-the-middle attacks, session hijacking, credential theft

### 10. WebSocket Security
**Assumption**: WebSocket connections authenticate via JWT.

**Requirements**:
- JWT passed in connection handshake
- Channel authorization enforced server-side
- Tenant isolation in channel names
- Connection rate limiting

**Risk if violated**: Unauthorized real-time data access, chat eavesdropping

## Operational Assumptions

### 11. Log Integrity
**Assumption**: Application logs are tamper-evident and retained.

**Requirements**:
- Logs shipped to external SIEM (e.g., Elasticsearch, Splunk)
- Audit log hash-chain verified daily
- Log retention: 1 year minimum
- Immutable after creation

**Risk if violated**: Incident investigation impossible, compliance violation

### 12. Backup Security
**Assumption**: Backups are encrypted and tested regularly.

**Requirements**:
- Encrypted at rest (AES-256)
- Stored in geographically separate location
- Restore tested monthly
- Backup keys stored separately from data keys

**Risk if violated**: Data loss, ransomware impact, regulatory non-compliance

### 13. Dependency Security
**Assumption**: Third-party packages are vetted and updated.

**Requirements**:
- `composer audit` run in CI/CD
- Known vulnerabilities patched within 7 days (critical) or 30 days (high)
- Dependabot or Renovate enabled
- No packages with unresolved critical issues

**Risk if violated**: Exploitation via known CVEs, supply chain attacks

## Financial Assumptions

### 14. Payment Gateway Security
**Assumption**: Payment gateways (Midtrans, Xendit) enforce PCI-DSS compliance.

**Requirements**:
- Never store credit card details
- Use tokenization for recurring payments
- Webhook signatures verified
- API keys rotated quarterly

**Risk if violated**: Payment fraud, card data breach, financial loss

### 15. Reconciliation Accuracy
**Assumption**: Automated reconciliation detects discrepancies within 24 hours.

**Requirements**:
- Daily reconciliation job runs at 01:00
- Sum of wallet balances = sum of ledger credits - debits
- Escrow held balance = sum of escrow amounts in non-final states
- Alerts sent on mismatch >0.01 IDR

**Risk if violated**: Silent financial leakage, accounting fraud undetected

## Compliance Assumptions

### 16. KYC/AML Processes
**Assumption**: Manual KYC review is performed by trained staff.

**Requirements**:
- Withdrawals >10M IDR require KYC
- High-risk users flagged by risk engine require manual review
- KYC documents retained for 5 years post-closure
- Staff trained on AML red flags

**Risk if violated**: Money laundering, regulatory penalties, license revocation

### 17. Data Residency
**Assumption**: Indonesian data residency laws are respected.

**Requirements**:
- Database hosted in Indonesian data center
- Backups do not leave Indonesia
- Third-party processors (if any) compliant with PP 71/2019

**Risk if violated**: Regulatory fines, service suspension

## Incident Response Assumptions

### 18. Monitoring & Alerting
**Assumption**: Critical events trigger immediate alerts.

**Requirements**:
- Failed audit chain verification → Email + SMS to ops team
- Ledger mismatch → Immediate alert
- Multiple failed admin logins → Security team notified
- Unusual withdrawal patterns → Risk team alerted

**Risk if violated**: Delayed incident response, extended breach window

### 19. Kill-Switch Availability
**Assumption**: Kill-switch can halt operations within 60 seconds.

**Requirements**:
- Maker-checker approval (Phase I)
- Step-up authentication required
- Disables: new escrows, withdrawals, transfers
- Allows: deposits, customer support access

**Risk if violated**: Unable to contain ongoing attack

## Third-Party Assumptions

### 20. API Partner Security
**Assumption**: Partner APIs (banks, payment gateways) are secure.

**Requirements**:
- Mutual TLS where supported
- API keys rotated quarterly
- Webhook signature verification
- Rate limiting on inbound webhooks

**Risk if violated**: Fake deposit notifications, withdrawal spoofing

## Development Assumptions

### 21. Code Review
**Assumption**: All code changes are peer-reviewed before merge.

**Requirements**:
- Minimum 1 approval required
- Security-sensitive changes require 2 approvals
- Automated tests pass
- No direct commits to main branch

**Risk if violated**: Bugs, vulnerabilities, backdoors introduced

### 22. Secrets Management
**Assumption**: Secrets are never committed to version control.

**Requirements**:
- Environment variables for all secrets
- `.env` file in `.gitignore`
- Secret scanning in CI/CD (e.g., Gitleaks)
- Secrets rotated immediately if exposed

**Risk if violated**: Credential exposure, full system compromise

## Summary

These assumptions form the foundation of Rekberkan's security posture. **Regular audits should verify these assumptions remain valid**. Any assumption violation should trigger immediate incident response and risk reassessment.

**Review Schedule**: Quarterly, or after any significant architecture change.
