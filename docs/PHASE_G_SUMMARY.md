# Phase G: Chat, Dispute, Notifications, WORM Audit - Completion Summary

## Overview
Phase G implements immutable chat, maker-checker dispute resolution, step-up authentication, real-time notifications, and tamper-evident audit logging with hash-chain verification.

## Delivered Components

### 1. WORM Audit Log with Hash-Chain
**Location**: `app/Services/AuditService.php`

**Features**:
- Cryptographic hash-chain linking all audit records
- Each record stores: `prev_hash` → `record_hash`
- SHA256 hashing of: tenant_id, event_type, subject, metadata, prev_hash, timestamp
- Tamper detection via chain verification
- PostgreSQL triggers prevent UPDATE/DELETE

**Database**:
- `audit_log` table (WORM enforced)
- Tenant isolated via RLS
- Indexed by event_type, created_at, subject

**Verification**:
```bash
php artisan audit:verify
php artisan audit:verify --tenant=1
```

Returns integrity report:
- Total records verified
- Issues detected (prev_hash mismatch, record_hash tampering)
- Pass/fail status (exit code 0 = pass, 1 = fail)

**Scheduled**: Runs daily at 02:00, emails on failure

### 2. Dispute Workflow (Maker-Checker)
**Location**: `app/Services/DisputeService.php`

**Four-Eyes Principle**:
1. **Maker** (Admin 1) submits action with step-up auth
2. **Checker** (Admin 2, different from maker) approves with step-up auth
3. Action executes only after approval
4. Enforced at service layer: `if ($maker === $checker) throw Exception`

**Action Types**:
- `PARTIAL_RELEASE`: Release portion of escrow to seller
- `FULL_REFUND`: Return full amount to buyer
- `FULL_RELEASE`: Release full amount to seller
- `REJECT`: Deny the dispute claim
- `REQUEST_INFO`: Request additional evidence

**Database**:
- `disputes` table: Open, investigating, resolved, closed
- `dispute_actions` table: Immutable action records with:
  - `maker_admin_id` (submitter)
  - `checker_admin_id` (approver)
  - `payload_snapshot` (action details)
  - `snapshot_hash` (SHA256 for tamper evidence)
  - `submitted_at`, `approved_at`, `executed_at` timestamps
  - `posting_batch_id` (links to financial ledger)

**Audit Trail**:
- `DISPUTE_ACTION_SUBMITTED` event
- `DISPUTE_ACTION_APPROVED` event
- `DISPUTE_ACTION_EXECUTED` event

### 3. Step-Up Authentication
**Location**: `app/Services/StepUpAuthService.php`

**Purpose**: Additional verification for sensitive operations

**Token Characteristics**:
- 64-character random string
- SHA256 hashed for storage
- 5-minute TTL (configurable)
- One-time use only
- Bound to: subject (User/Admin), subject_id, purpose
- Optional device fingerprint binding

**Required For**:
- Withdrawals above threshold
- Dispute action submission/approval
- Admin force release/refund
- Kill-switch activation (Phase I)
- Risk action overrides

**Workflow**:
```php
// 1. Generate token (e.g., via email/SMS)
$token = $stepUpService->generate(
    tenantId: 1,
    subjectType: 'Admin',
    subjectId: 42,
    purpose: 'dispute_action_approve',
    ttlMinutes: 5
);

// 2. User provides token
// 3. Verify and consume (marks as used)
$stepUpService->verifyAndConsume(
    token: $token,
    expectedSubjectType: 'Admin',
    expectedSubjectId: 42,
    expectedPurpose: 'dispute_action_approve'
);
```

**Cleanup**: Hourly scheduled job removes expired tokens

### 4. Immutable Chat
**Location**: `app/Services/ChatService.php`

**Features**:
- One chat room per escrow
- Messages cannot be edited or deleted (DB triggers)
- PII-safe logging (only message ID logged, not content)
- Max 5000 characters per message
- Admin join only if escrow is DISPUTED
- Real-time broadcasting via WebSocket

**Database**:
- `chat_messages` table (immutable via triggers)
- Polymorphic `sender` (User or Admin)
- Tenant isolated

**Participant Validation**:
- Buyer: Always allowed
- Seller: Always allowed
- Admin: Only if `escrow.status = 'DISPUTED'`
- Others: Rejected

**WebSocket**:
- Channel: `tenant.{tenant_id}.escrow.{escrow_id}.chat`
- Event: `message.sent`
- Broadcast to all participants except sender

### 5. Notifications
**Location**: `app/Services/NotificationService.php`

**Types**:
- `ESCROW_STATUS_CHANGED`
- `WALLET_DEPOSIT`
- `WALLET_WITHDRAWAL`
- `WALLET_FEE`
- `DISPUTE_OPENED`
- `DISPUTE_UPDATED`
- `DISPUTE_RESOLVED`
- `RISK_ACTION`
- `CHAT_MESSAGE`
- `SYSTEM_ANNOUNCEMENT`

**Database**:
- `notifications` table (insert-only, immutable)
- `notification_reads` table (separate read tracking)
- One read record per notification (unique constraint)

**Delivery**:
- In-app: Always created in database
- WebSocket: Real-time broadcast to user's personal channel
- Email: Queued job (future enhancement)
- Push: Mobile push notification (future enhancement)

**WebSocket**:
- Channel: `tenant.{tenant_id}.user.{user_id}`
- Event: `notification.created`
- Personal channel, only accessible by user

## Testing Coverage

### Audit Chain Tests (`tests/Feature/AuditChainTest.php`)
- ✅ Hash-chain creation (prev_hash → record_hash)
- ✅ Chain verification passes on valid data
- ✅ Immutability enforcement (UPDATE blocked)
- ✅ Delete prevention (DELETE blocked)
- ✅ Tampering detection (modified hash detected)

### Dispute Tests (`tests/Feature/DisputeMakerCheckerTest.php`)
- ✅ Maker submits with step-up token
- ✅ Submission fails without valid token
- ✅ Four-eyes enforcement (maker ≠ checker)
- ✅ Checker approves with step-up token
- ✅ Token single-use enforcement

### Chat Tests (`tests/Feature/ChatImmutabilityTest.php`)
- ✅ Message creation succeeds
- ✅ Message update blocked (immutable)
- ✅ Message delete blocked (immutable)
- ✅ Non-participant rejection
- ✅ Empty message rejection
- ✅ Max length enforcement (5000 chars)

## Database Invariants Enforced

### audit_log
```sql
CREATE TRIGGER prevent_audit_log_update
    BEFORE UPDATE ON audit_log
    FOR EACH ROW EXECUTE FUNCTION prevent_audit_log_mutation();

CREATE TRIGGER prevent_audit_log_delete
    BEFORE DELETE ON audit_log
    FOR EACH ROW EXECUTE FUNCTION prevent_audit_log_mutation();
```

### chat_messages
```sql
CREATE TRIGGER prevent_chat_message_update
    BEFORE UPDATE ON chat_messages
    FOR EACH ROW EXECUTE FUNCTION prevent_chat_message_mutation();

CREATE TRIGGER prevent_chat_message_delete
    BEFORE DELETE ON chat_messages
    FOR EACH ROW EXECUTE FUNCTION prevent_chat_message_mutation();
```

### notifications
```sql
CREATE TRIGGER prevent_notification_update
    BEFORE UPDATE ON notifications
    FOR EACH ROW EXECUTE FUNCTION prevent_notification_mutation();

CREATE TRIGGER prevent_notification_delete
    BEFORE DELETE ON notifications
    FOR EACH ROW EXECUTE FUNCTION prevent_notification_mutation();
```

## Security Considerations

### Maker-Checker Separation
- Enforced at service layer (not just UI)
- Requires step-up auth for both maker and checker
- Payload snapshot hashed for tamper evidence
- All actions logged to audit_log

### Step-Up Token Security
- Short TTL (5 min default)
- One-time use enforced at DB level (`used` flag)
- Token hash stored (not plaintext)
- Purpose-specific (cannot be reused for different action)
- Device fingerprint binding (optional)

### PII Protection
- Chat message bodies never logged in app logs
- Only message IDs referenced in audit logs
- Notifications do not expose sensitive data in metadata
- WebSocket payloads sanitized

### Tamper Evidence
- Audit log hash-chain prevents silent tampering
- Any modification breaks chain verification
- Dispute action payloads hashed (SHA256)
- All critical operations emit audit events

## WebSocket Implementation

**See**: `docs/websocket.md` for full documentation

**Channels**:
1. `tenant.{tid}.escrow.{eid}.chat` - Escrow chat
2. `tenant.{tid}.user.{uid}` - Personal notifications

**Authorization**:
- JWT token required
- Tenant ID verified
- Resource access checked (e.g., buyer/seller for escrow)

**Rate Limiting**:
- 10 connection attempts/min per IP
- 30 messages/min per user
- 20 channel subscriptions per connection

## Operational Tools

### Commands
```bash
# Verify audit chain integrity
php artisan audit:verify
php artisan audit:verify --tenant=1

# Cleanup expired step-up tokens
php artisan step-up:cleanup
```

### Scheduled Jobs
- `audit:verify` - Daily at 02:00, email on failure
- `step-up:cleanup` - Hourly, removes expired tokens

## Audit Event Types

Phase G introduces:
- `RISK_EVALUATION` (Phase F)
- `VOUCHER_REDEEMED` (Phase F)
- `CAMPAIGN_ENROLLMENT` (Phase F)
- `DISPUTE_ACTION_SUBMITTED` ✅
- `DISPUTE_ACTION_APPROVED` ✅
- `DISPUTE_ACTION_EXECUTED` ✅
- `CHAT_MESSAGE_SENT` ✅

All events traceable via:
- `subject_type` + `subject_id`
- `actor_type` + `actor_id`
- `metadata` JSON
- Hash-chain linkage

## Next Steps (Phase H)

- Rate limiting middleware
- Security headers (HSTS, CSP, X-Frame-Options)
- Complete OpenAPI specification
- CI/CD workflow enhancements
- Documentation (9 files)
- Final hardening and polish
