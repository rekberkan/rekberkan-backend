# Financial Invariants

## Overview
These invariants MUST hold true at all times. Violation indicates a critical bug or fraud. Automated checks run daily at 01:00 WIB.

## Core Invariants

### I-001: Wallet Balance Consistency
**Statement**: For any wallet, the balance equals the sum of all ledger entries.

**Formula**:
```sql
SELECT 
    w.id AS wallet_id,
    w.balance AS wallet_balance,
    COALESCE(SUM(wl.amount), 0) AS ledger_sum
FROM wallets w
LEFT JOIN wallet_ledger wl ON wl.wallet_id = w.id
GROUP BY w.id, w.balance
HAVING w.balance != COALESCE(SUM(wl.amount), 0);
```

**Expected**: 0 rows returned

**Violation Impact**: CRITICAL - Indicates ledger tampering or calculation error

**Remediation**:
1. Freeze all financial operations via kill-switch
2. Export wallet and ledger snapshots
3. Identify discrepancy source (audit log review)
4. Create correction entry with maker-checker approval
5. Verify invariant holds after correction

---

### I-002: Platform Solvency
**Statement**: Sum of all user wallet balances equals sum of platform liabilities.

**Formula**:
```sql
-- User wallets (liabilities)
SELECT SUM(balance) AS total_user_balances FROM wallets;

-- Platform held funds (assets)
SELECT 
    SUM(CASE WHEN transaction_type IN ('DEPOSIT', 'FEE_COLLECTED') THEN amount ELSE 0 END) -
    SUM(CASE WHEN transaction_type = 'WITHDRAWAL' THEN amount ELSE 0 END) 
AS platform_balance
FROM wallet_ledger;

-- Must match within 0.01 IDR (rounding tolerance)
```

**Expected**: `ABS(total_user_balances - platform_balance) < 0.01`

**Violation Impact**: CRITICAL - Insolvency or untracked funds

**Remediation**:
1. Immediate kill-switch activation
2. Contact payment gateway for external reconciliation
3. Review recent deposits/withdrawals
4. Identify missing/duplicate transactions
5. Coordinate with finance team for bank statement comparison

---

### I-003: Escrow Conservation
**Statement**: Funds in PENDING/FUNDED escrows equal sum of escrow_ledger HOLD entries.

**Formula**:
```sql
-- Escrows in active states
SELECT SUM(amount) AS total_escrow_held
FROM escrows
WHERE status IN ('PENDING', 'FUNDED', 'SHIPPED', 'DISPUTED');

-- Corresponding ledger holds
SELECT SUM(amount) AS total_ledger_holds
FROM wallet_ledger
WHERE transaction_type = 'ESCROW_HOLD'
AND escrow_id IN (
    SELECT id FROM escrows 
    WHERE status IN ('PENDING', 'FUNDED', 'SHIPPED', 'DISPUTED')
);
```

**Expected**: `total_escrow_held = total_ledger_holds`

**Violation Impact**: HIGH - Escrow double-spend or orphaned holds

**Remediation**:
1. Identify mismatched escrows
2. Check for state transition bugs
3. Review recent escrow operations in audit log
4. Manual reconciliation with step-up auth
5. Update escrow status or create correction entry

---

### I-004: Transaction Idempotency
**Statement**: No duplicate idempotency keys for successful transactions.

**Formula**:
```sql
SELECT idempotency_key, COUNT(*) AS occurrence_count
FROM wallet_ledger
WHERE idempotency_key IS NOT NULL
GROUP BY idempotency_key
HAVING COUNT(*) > 1;
```

**Expected**: 0 rows returned

**Violation Impact**: HIGH - Duplicate charge or refund

**Remediation**:
1. Identify duplicate transactions
2. Determine which is legitimate (check timestamps, IPs)
3. Reverse fraudulent transaction
4. Refund affected user
5. Fix idempotency key generation bug

---

### I-005: Fee Collection Integrity
**Statement**: Platform fee balance equals sum of fee ledger entries.

**Formula**:
```sql
SELECT SUM(amount) AS collected_fees
FROM wallet_ledger
WHERE transaction_type = 'FEE_COLLECTED';

-- Must equal platform fee wallet balance
SELECT balance FROM wallets WHERE wallet_type = 'PLATFORM_FEE';
```

**Expected**: `collected_fees = platform_fee_wallet.balance`

**Violation Impact**: MEDIUM - Fee revenue leakage

**Remediation**:
1. Review fee calculation logic
2. Check for missing fee entries
3. Verify fee percentages applied correctly
4. Adjust fee wallet if necessary

---

### I-006: No Negative Balances
**Statement**: All wallet balances are non-negative.

**Formula**:
```sql
SELECT id, balance, user_id
FROM wallets
WHERE balance < 0;
```

**Expected**: 0 rows returned

**Violation Impact**: CRITICAL - Overdraft or calculation error

**Remediation**:
1. Freeze affected wallet immediately
2. Identify transaction causing overdraft
3. Check for race condition in withdrawal logic
4. Reverse unauthorized transactions
5. Restore balance to 0 or last valid state

---

### I-007: Audit Chain Integrity
**Statement**: Audit log hash-chain is unbroken.

**Formula**:
```bash
php artisan audit:verify --tenant=all
```

**Expected**: Exit code 0, "integrity_valid": true

**Violation Impact**: CRITICAL - Evidence of tampering

**Remediation**:
1. Immediate security incident declaration
2. Preserve current state (database dump)
3. Identify tampered records
4. Review database access logs
5. Rotate database credentials
6. Restore from backup if necessary
7. Notify legal/compliance

---

### I-008: Dispute Resolution Completeness
**Statement**: All RESOLVED disputes have corresponding ledger entries.

**Formula**:
```sql
SELECT d.id AS dispute_id
FROM disputes d
JOIN dispute_actions da ON da.dispute_id = d.id
WHERE d.status = 'RESOLVED'
AND da.approval_status = 'APPROVED'
AND da.executed_at IS NOT NULL
AND da.posting_batch_id IS NULL;
```

**Expected**: 0 rows returned

**Violation Impact**: HIGH - Approved action not executed

**Remediation**:
1. Identify pending dispute actions
2. Check for execution errors in logs
3. Manually execute with maker-checker
4. Update posting_batch_id
5. Verify funds released/refunded

---

### I-009: Withdrawal Limits
**Statement**: No withdrawal exceeds daily limit without KYC.

**Formula**:
```sql
SELECT 
    user_id,
    DATE(created_at) AS withdrawal_date,
    SUM(ABS(amount)) AS daily_withdrawal
FROM wallet_ledger
WHERE transaction_type = 'WITHDRAWAL'
AND created_at > CURRENT_DATE - INTERVAL '30 days'
GROUP BY user_id, DATE(created_at)
HAVING SUM(ABS(amount)) > 10000000 -- 10M IDR
AND user_id NOT IN (SELECT user_id FROM kyc_verifications WHERE status = 'APPROVED');
```

**Expected**: 0 rows returned

**Violation Impact**: HIGH - Compliance violation (AML/KYC bypass)

**Remediation**:
1. Flag user accounts
2. Freeze further withdrawals
3. Request mandatory KYC
4. Review transactions for suspicious activity
5. Report to compliance officer

---

### I-010: Risk Action Enforcement
**Statement**: Users with frozen wallets have no new transactions.

**Formula**:
```sql
SELECT u.id AS user_id, wl.id AS ledger_id
FROM users u
JOIN wallet_ledger wl ON wl.wallet_id IN (
    SELECT id FROM wallets WHERE user_id = u.id
)
WHERE u.wallet_frozen_at IS NOT NULL
AND wl.created_at > u.wallet_frozen_at
AND wl.transaction_type IN ('WITHDRAWAL', 'TRANSFER');
```

**Expected**: 0 rows returned

**Violation Impact**: HIGH - Risk control bypass

**Remediation**:
1. Identify bypassed transactions
2. Check for authorization logic bug
3. Reverse unauthorized transactions
4. Strengthen wallet freeze checks
5. Review risk engine rules

---

## Automated Verification

### Daily Reconciliation Job

**Schedule**: 01:00 WIB daily

**Command**:
```bash
php artisan reconcile:run --date=$(date +%Y-%m-%d)
```

**Output**:
```json
{
    "date": "2026-01-11",
    "status": "PASS" | "FAIL",
    "invariants_checked": 10,
    "invariants_passed": 10,
    "invariants_failed": 0,
    "details": [
        {"code": "I-001", "status": "PASS"},
        {"code": "I-002", "status": "PASS"}
    ]
}
```

**Alert on Failure**:
- Email to: ops@rekberkan.com, finance@rekberkan.com
- SMS to: On-call engineer
- Slack: #alerts channel
- Status: Creates S1 incident ticket

### On-Demand Verification

```bash
# Check specific invariant
php artisan reconcile:check --invariant=I-001

# Check all for specific tenant
php artisan reconcile:run --tenant=5

# Export discrepancies
php artisan reconcile:run --export=/tmp/discrepancies.csv
```

---

## Monitoring Dashboard

### Metrics to Track

1. **Invariant Health**: % of invariants passing
2. **Ledger Balance**: Real-time sum of all wallet balances
3. **Escrow Held**: Total funds in active escrows
4. **Daily Transactions**: Volume and sum by type
5. **Failed Verifications**: Count and trend

### Alerts

| Condition | Severity | Action |
|-----------|----------|--------|
| Any invariant fails | S1 - Critical | Freeze operations, page on-call |
| Ledger mismatch >10,000 IDR | S2 - High | Investigate within 1 hour |
| 3+ failed reconciliations | S1 - Critical | Manual intervention required |
| Audit chain broken | S1 - Critical | Security incident declared |

---

## Historical Data

### Invariant Violations Log

```sql
CREATE TABLE invariant_violations (
    id BIGSERIAL PRIMARY KEY,
    invariant_code VARCHAR(10),
    detected_at TIMESTAMP,
    severity VARCHAR(20),
    description TEXT,
    affected_records JSONB,
    resolved_at TIMESTAMP,
    resolution_notes TEXT
);
```

### Audit Trail

All invariant checks logged to `audit_log`:
```json
{
    "event_type": "INVARIANT_CHECK",
    "metadata": {
        "invariant_code": "I-001",
        "status": "PASS",
        "checked_at": "2026-01-11T01:00:00+07:00"
    }
}
```

---

## Compliance

### Regulatory Requirements

- **ISO 27001**: Maintain financial integrity controls
- **SOC 2**: Automated reconciliation with evidence
- **PCI-DSS**: No card data in ledger (N/A - tokenized)
- **OJK**: Daily reconciliation for fintech platforms

### Evidence Collection

- Daily reconciliation reports (retained 7 years)
- Invariant violation tickets (retained permanently)
- Correction entries with maker-checker approval
- Audit log preservation

---

## Best Practices

1. **Never bypass checks**: Even in "emergency" 
2. **Investigate all failures**: No false positives acceptable
3. **Document corrections**: Every manual adjustment logged
4. **Test invariants**: Verify logic in staging
5. **Monitor trends**: Patterns may indicate systemic issues
6. **Quarterly review**: Update invariants as platform evolves

---

## Contact

For invariant violations:
- **Primary**: Finance Lead
- **Technical**: Engineering Lead  
- **Escalation**: CFO + CTO
- **Emergency**: Activate incident response plan
