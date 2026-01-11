# Phase F: Risk & Promotions - Completion Summary

## Overview
Phase F implements a deterministic risk engine, behavior logging system, and promotional features (vouchers and campaigns) with atomic operations and tamper-evident audit trails.

## Delivered Components

### 1. Risk Engine (v1.0.0)
**Location**: `app/Services/RiskEngine.php`

**Features**:
- Deterministic scoring algorithm (0-100)
- Versioned evaluation (engine_version stored)
- Input snapshot with SHA256 hash for tamper evidence
- Four risk action levels:
  - LOW (0-24): Normal operation
  - MEDIUM (25-49): 24h withdraw delay
  - HIGH (50-74): Wallet freeze
  - CRITICAL (75-100): Permanent lock + KYC required

**Signals Evaluated**:
- Account age (days)
- Dispute ratio
- Cancellation ratio
- Voucher abuse count (30 days)
- Rapid withdrawals (7 days)
- Device changes (30 days)
- IP changes (30 days)

**Database**:
- `risk_decisions` table (WORM enforced)
- Immutability via DB triggers
- RLS tenant isolation
- Cannot be updated or deleted post-creation

### 2. Behavior Logging
**Location**: `app/Services/BehaviorLogger.php`

**Tracked Events**:
- `VOUCHER_REDEMPTION_ATTEMPT`
- `VOUCHER_REDEMPTION_SUCCESS`
- `VOUCHER_REDEMPTION_FAILED`
- `AUTH_FAILURE`
- `RAPID_WITHDRAWAL`
- `DEVICE_CHANGE`
- `IP_CHANGE`
- `ESCROW_CANCELLATION`
- `SUSPICIOUS_ACTIVITY`

**Database**:
- `user_behavior_log` table (append-only)
- Immutability via DB triggers
- IP address and user agent capture
- Metadata stored as JSON

### 3. Voucher System
**Location**: `app/Services/VoucherService.php`

**Features**:
- Atomic redemption with row-level locking
- Per-user usage limits
- Global usage limits
- Time-based validity (valid_from, valid_until)
- Two types:
  - PERCENTAGE: Discount percentage
  - FIXED_AMOUNT: Fixed discount amount
- Idempotency key enforcement

**Database**:
- `vouchers` table (mutable for usage_count)
- `voucher_redemptions` table (WORM enforced)
- Concurrent redemption safe via SELECT FOR UPDATE

### 4. Campaign System
**Location**: `app/Services/CampaignService.php`

**Features**:
- Budget tracking (total and used)
- Participant limits
- Time-based campaigns
- Eligibility checks:
  - FIRST_ESCROW_FREE: User must have zero escrows
  - Extensible for other campaign types
- Atomic enrollment with locking

**Database**:
- `campaigns` table
- `campaign_participations` table
- One participation per user per campaign (unique constraint)

## Testing Coverage

### Risk Engine Tests (`tests/Feature/RiskEngineTest.php`)
- ✅ Deterministic score calculation
- ✅ High dispute ratio increases score
- ✅ Voucher abuse increases score
- ✅ Action threshold enforcement
- ✅ Risk decision immutability
- ✅ Snapshot hash verification

### Voucher Tests (`tests/Feature/VoucherRedemptionTest.php`)
- ✅ Successful redemption
- ✅ Per-user limit enforcement
- ✅ Total usage limit enforcement
- ✅ Concurrent redemption safety
- ✅ Redemption immutability
- ✅ Invalid voucher rejection
- ✅ Expired voucher rejection

### Campaign Tests (`tests/Feature/CampaignEligibilityTest.php`)
- ✅ First escrow campaign enrollment
- ✅ Existing escrow users rejected
- ✅ Duplicate enrollment prevention
- ✅ Max participants enforcement
- ✅ Budget limit enforcement
- ✅ Inactive campaign rejection

## Audit Trail Integration

All Phase F operations emit audit events:
- `RISK_EVALUATION`: When user risk is scored
- `VOUCHER_REDEEMED`: When voucher is successfully used
- `CAMPAIGN_ENROLLMENT`: When user enrolls in campaign

All events are traceable via:
- `subject_type` and `subject_id`
- `metadata` JSON with operation details
- Correlation to idempotency keys where applicable

## Database Invariants Enforced

### risk_decisions
```sql
CREATE TRIGGER prevent_risk_decision_update
    BEFORE UPDATE ON risk_decisions
    FOR EACH ROW EXECUTE FUNCTION prevent_risk_decision_mutation();

CREATE TRIGGER prevent_risk_decision_delete
    BEFORE DELETE ON risk_decisions
    FOR EACH ROW EXECUTE FUNCTION prevent_risk_decision_mutation();
```

### user_behavior_log
```sql
CREATE TRIGGER prevent_behavior_log_update
    BEFORE UPDATE ON user_behavior_log
    FOR EACH ROW EXECUTE FUNCTION prevent_behavior_log_mutation();

CREATE TRIGGER prevent_behavior_log_delete
    BEFORE DELETE ON user_behavior_log
    FOR EACH ROW EXECUTE FUNCTION prevent_behavior_log_mutation();
```

### voucher_redemptions
```sql
CREATE TRIGGER prevent_voucher_redemption_update
    BEFORE UPDATE ON voucher_redemptions
    FOR EACH ROW EXECUTE FUNCTION prevent_voucher_redemption_mutation();

CREATE TRIGGER prevent_voucher_redemption_delete
    BEFORE DELETE ON voucher_redemptions
    FOR EACH ROW EXECUTE FUNCTION prevent_voucher_redemption_mutation();
```

## Security Considerations

1. **Determinism**: Risk scores are deterministic based on input snapshot
2. **Tamper Evidence**: SHA256 hash of input snapshot prevents tampering
3. **Immutability**: Core tables cannot be modified post-creation
4. **Tenant Isolation**: RLS policies enforce tenant boundaries
5. **Concurrency Safety**: Atomic operations with row-level locking
6. **Idempotency**: All financial impacts use idempotency keys

## Next Steps (Phase G)

- Chat with immutable messages
- Dispute workflow with maker-checker
- Step-up authentication for sensitive operations
- Notifications with WebSocket broadcasting
- WORM audit log with hash-chain verification
