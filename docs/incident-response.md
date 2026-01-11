# Incident Response Plan

## Overview
This document defines Rekberkan's incident response procedures for security and operational incidents.

## Severity Levels

### S1 - Critical (Response: Immediate)
- Complete service outage
- Active data breach
- Financial fraud in progress
- Ledger integrity compromised
- Payment gateway failure

### S2 - High (Response: <1 hour)
- Partial service degradation
- Suspected unauthorized access
- Failed audit chain verification
- Significant performance degradation
- Multiple user complaints

### S3 - Medium (Response: <4 hours)
- Minor service issues
- Elevated error rates
- Suspicious activity detected
- Failed reconciliation (minor)

### S4 - Low (Response: <24 hours)
- Documentation issues
- Non-critical bugs
- Feature requests

## Incident Response Team

### Roles
- **Incident Commander (IC)**: Coordinates response, makes decisions
- **Technical Lead**: Investigates root cause, implements fixes
- **Communications Lead**: Handles user/stakeholder communications
- **Security Lead**: Assesses security implications, contains threats
- **Finance Lead**: Assesses financial impact, coordinates reconciliation

### On-Call Rotation
- Primary: 24/7 on-call engineer
- Secondary: Backup engineer
- Escalation: CTO/Security Officer

## Incident Response Phases

### 1. Detection & Triage (0-15 minutes)

**Actions**:
1. Acknowledge alert in monitoring system
2. Assess severity level
3. Create incident ticket
4. Notify Incident Commander

**Monitoring Channels**:
- Application logs (Elasticsearch)
- Error tracking (Sentry)
- APM (New Relic/Datadog)
- Audit chain verification (daily)
- User reports (support tickets)

### 2. Initial Response (15-60 minutes)

**Actions**:
1. **Assemble team**: Page relevant specialists
2. **Assess scope**: How many users/tenants affected?
3. **Contain threat**: Implement immediate mitigations
4. **Communicate**: Update status page

**Communication Template**:
```
Incident: [Brief description]
Severity: S1/S2/S3/S4
Affected: [Users/Tenants/Features]
Status: Investigating / Identified / Monitoring / Resolved
Next Update: [Time]
```

### 3. Investigation & Containment (1-4 hours)

**Technical Investigation**:
- Collect logs, metrics, traces
- Review recent deployments/changes
- Check audit log for anomalies
- Interview users (if needed)

**Containment Strategies**:

#### For Security Incidents:
- [ ] Rotate compromised credentials
- [ ] Revoke suspicious JWT tokens
- [ ] Block malicious IPs at firewall
- [ ] Enable kill-switch (if necessary)
- [ ] Isolate affected tenants

#### For Financial Incidents:
- [ ] Freeze affected wallets
- [ ] Halt withdrawals via kill-switch
- [ ] Export ledger snapshot
- [ ] Contact payment gateway
- [ ] Preserve evidence (database dumps)

#### For Availability Incidents:
- [ ] Scale up resources
- [ ] Enable maintenance mode
- [ ] Failover to backup region
- [ ] Optimize slow queries
- [ ] Clear Redis cache (if safe)

### 4. Resolution (4-24 hours)

**Root Cause Identification**:
- Reproduce issue in staging
- Identify code/config causing problem
- Determine contributing factors

**Remediation**:
- Deploy hotfix (if code issue)
- Update configuration
- Run data migration/correction
- Verify fix in production

**Verification**:
- Monitor error rates for 1 hour
- Verify audit chain integrity
- Run reconciliation
- Confirm with affected users

### 5. Recovery (24-72 hours)

**Data Recovery (if needed)**:
- Restore from backup
- Replay audit log from last known-good state
- Manual ledger corrections (maker-checker)
- Re-credit affected users

**Service Restoration**:
- Disable kill-switch
- Gradually increase traffic
- Monitor for regression
- Update status page to "Resolved"

### 6. Post-Incident Review (Within 7 days)

**Post-Mortem Template**:
```markdown
# Incident Post-Mortem: [Title]

## Incident Summary
- Date: YYYY-MM-DD
- Severity: S1/S2/S3/S4
- Duration: X hours
- Impact: [Affected users/revenue]

## Timeline
- HH:MM - Incident detected
- HH:MM - Team assembled
- HH:MM - Root cause identified
- HH:MM - Fix deployed
- HH:MM - Incident resolved

## Root Cause
[Detailed technical explanation]

## Contributing Factors
1. [Factor 1]
2. [Factor 2]

## What Went Well
- [Success 1]
- [Success 2]

## What Went Poorly
- [Issue 1]
- [Issue 2]

## Action Items
| Action | Owner | Deadline | Priority |
|--------|-------|----------|----------|
| Fix X | Dev Team | YYYY-MM-DD | P0 |
| Document Y | Ops Team | YYYY-MM-DD | P1 |

## Lessons Learned
[Key takeaways]
```

## Specific Incident Runbooks

### Runbook: Ledger Mismatch Detected

**Trigger**: Daily reconciliation job fails

**Steps**:
1. **Freeze financial operations**:
   ```bash
   php artisan kill-switch:enable --reason="Ledger mismatch detected"
   ```

2. **Export ledger snapshot**:
   ```bash
   php artisan reconcile:export --date=today --format=csv
   ```

3. **Verify audit chain**:
   ```bash
   php artisan audit:verify
   ```

4. **Identify discrepancy**:
   - Compare sum(wallet_ledger.amount) WHERE amount > 0 vs < 0
   - Check for duplicate idempotency keys
   - Review recent manual adjustments

5. **Correct if necessary**:
   - Create correction entry (maker-checker required)
   - Document in incident ticket
   - Log to audit_log

6. **Re-run reconciliation**:
   ```bash
   php artisan reconcile:run --date=today
   ```

7. **Resume operations if passed**:
   ```bash
   php artisan kill-switch:disable
   ```

---

### Runbook: Audit Chain Tamper Detected

**Trigger**: `audit:verify` command fails

**Steps**:
1. **CRITICAL: Do not delete evidence**
2. **Immediately freeze all operations**:
   ```bash
   php artisan kill-switch:enable --reason="Audit tampering detected"
   ```

3. **Export audit log**:
   ```bash
   pg_dump -t audit_log -F c -f /backups/audit_log_$(date +%s).dump
   ```

4. **Run detailed verification**:
   ```bash
   php artisan audit:verify --tenant=all --verbose
   ```

5. **Identify tampered records**:
   - Note record IDs from verification output
   - Check who has database access (DBA logs)
   - Review application logs for suspicious admin activity

6. **Preserve evidence**:
   - Screenshot verification output
   - Export affected records
   - Create forensic timeline

7. **Notify legal/compliance**:
   - Security incident report
   - Potential breach notification requirements

8. **Rebuild chain from backup** (if necessary):
   - Restore from last known-good backup
   - Replay transactions from payment gateway
   - Manually reconcile

9. **Update security**:
   - Rotate database credentials
   - Review database access policies
   - Enable connection logging

---

### Runbook: Suspected Account Takeover

**Trigger**: User reports unauthorized access

**Steps**:
1. **Verify report**:
   - Check user's recent login history
   - Review IP addresses and devices
   - Check for unusual transactions

2. **Freeze account**:
   ```sql
   UPDATE users SET status = 'FROZEN' 
   WHERE id = [user_id] AND tenant_id = [tenant_id];
   ```

3. **Revoke sessions**:
   ```bash
   php artisan tokens:revoke --user=[user_id]
   ```

4. **Review activity**:
   - Export user's audit log
   - Check wallet transactions
   - Review chat messages (metadata only)

5. **Rollback unauthorized actions** (if financial):
   - Identify fraudulent withdrawals
   - Create reversal entries (maker-checker)
   - Contact payment gateway if needed

6. **Contact user**:
   - Email notification of freeze
   - Request identity verification
   - Provide password reset link

7. **Re-enable account** (after verification):
   - User confirms identity via KYC
   - User resets password
   - Enable MFA (mandatory)
   - Unfreeze account

---

## Escalation Paths

### Technical Escalation
1. On-call engineer (Primary)
2. On-call engineer (Secondary)
3. Lead engineer
4. CTO

### Security Escalation
1. Security Lead
2. Security Officer
3. CISO (if available)
4. External security consultant

### Financial Escalation
1. Finance Lead
2. CFO
3. Board (for losses >$100k USD)

### Legal Escalation
1. Compliance Officer
2. Legal Counsel
3. Data Protection Officer (for breaches)

## Communication Protocols

### Internal Communication
- **Primary**: Slack #incidents channel
- **War Room**: Zoom/Google Meet link
- **Documentation**: Confluence incident page

### External Communication
- **Status Page**: status.rekberkan.com
- **Email**: Affected users via notification system
- **Social Media**: For S1 incidents only

### Regulatory Notification
- **Data Breach**: Notify BSSN within 72 hours (Indonesia)
- **Financial Fraud**: Notify OJK within 24 hours
- **Service Outage**: SLA-based notifications to enterprise clients

## Incident Metrics

### Track & Report
- Mean Time to Detect (MTTD)
- Mean Time to Acknowledge (MTTA)
- Mean Time to Resolve (MTTR)
- Incident count by severity
- Recurrence rate

### Target SLAs
- S1: MTTR < 4 hours
- S2: MTTR < 8 hours
- S3: MTTR < 24 hours
- S4: MTTR < 72 hours

## Tools & Access

### Required Access
- Database read/write credentials (encrypted vault)
- Production server SSH keys
- AWS/Cloud console access
- Monitoring system (Datadog/New Relic)
- Status page admin

### Emergency Contacts
- On-call phone numbers
- Payment gateway support hotline
- Cloud provider support
- DDoS mitigation provider

## Testing

Incident response plan tested:
- **Tabletop exercises**: Quarterly
- **Fire drills**: Bi-annually (simulate S1 incident)
- **Post-mortem reviews**: After every S1/S2 incident

**Last Test**: [Date]  
**Next Test**: [Date]
