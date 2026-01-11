# Key Rotation Procedures

## Overview
Regular key rotation is critical to limiting the impact of compromised credentials. This document defines rotation schedules and procedures for all secrets.

## Rotation Schedule

| Secret Type | Rotation Frequency | Last Rotated | Next Due |
|-------------|-------------------|--------------|----------|
| JWT Signing Key | 90 days | 2026-01-01 | 2026-04-01 |
| Database Password | 180 days | 2026-01-01 | 2026-07-01 |
| Redis Password | 180 days | 2026-01-01 | 2026-07-01 |
| API Keys (Payment Gateway) | 90 days | 2026-01-01 | 2026-04-01 |
| Webhook Secrets | 180 days | 2026-01-01 | 2026-07-01 |
| Encryption Keys (Storage) | 365 days | 2026-01-01 | 2027-01-01 |
| SSH Keys (Production) | 365 days | 2026-01-01 | 2027-01-01 |
| SSL/TLS Certificates | 90 days (auto-renewed) | Auto | Auto |

## JWT Signing Key Rotation

### Procedure

1. **Generate new key**:
   ```bash
   php artisan key:generate --show
   ```

2. **Add to environment** (don't replace old key yet):
   ```env
   JWT_SECRET_CURRENT=old_key_here
   JWT_SECRET_NEW=new_key_here
   ```

3. **Update application** to sign with new key but verify with both:
   ```php
   // config/jwt.php
   'secret' => env('JWT_SECRET_NEW'),
   'fallback_secrets' => [env('JWT_SECRET_CURRENT')],
   ```

4. **Deploy to production**:
   ```bash
   php artisan deploy:production
   ```

5. **Wait 24 hours** (allow old tokens to expire)

6. **Remove old key**:
   ```env
   JWT_SECRET=new_key_here
   # Remove JWT_SECRET_CURRENT and JWT_SECRET_NEW
   ```

7. **Update config**:
   ```php
   // config/jwt.php
   'secret' => env('JWT_SECRET'),
   'fallback_secrets' => [], // Remove fallback
   ```

8. **Deploy final change**

### Rollback
If issues detected:
```env
JWT_SECRET=old_key_here
```
Redeploy immediately.

---

## Database Password Rotation

### Procedure

1. **Create new database user** with same privileges:
   ```sql
   CREATE USER 'rekber_app_v2'@'%' IDENTIFIED BY 'new_strong_password';
   GRANT ALL PRIVILEGES ON rekber_db.* TO 'rekber_app_v2'@'%';
   FLUSH PRIVILEGES;
   ```

2. **Update environment variables** (but don't restart yet):
   ```env
   DB_USERNAME=rekber_app_v2
   DB_PASSWORD=new_strong_password
   ```

3. **Rolling deployment**:
   - Update instance 1, wait 5 minutes
   - Update instance 2, wait 5 minutes
   - Continue for all instances

4. **Verify connections**:
   ```bash
   php artisan tinker
   >>> DB::connection()->getPdo();
   ```

5. **Revoke old user** (after 24 hours):
   ```sql
   REVOKE ALL PRIVILEGES ON rekber_db.* FROM 'rekber_app_v1'@'%';
   DROP USER 'rekber_app_v1'@'%';
   ```

6. **Update documentation** with new username

---

## API Key Rotation (Payment Gateway)

### Midtrans/Xendit Rotation

1. **Generate new API key** in gateway dashboard

2. **Update environment** with both keys:
   ```env
   MIDTRANS_API_KEY_PRIMARY=old_key
   MIDTRANS_API_KEY_SECONDARY=new_key
   ```

3. **Update application** to try primary, fallback to secondary:
   ```php
   try {
       $response = $gateway->charge(['api_key' => config('midtrans.primary_key')]);
   } catch (AuthException $e) {
       $response = $gateway->charge(['api_key' => config('midtrans.secondary_key')]);
   }
   ```

4. **Deploy to production**

5. **Swap keys after 24 hours**:
   ```env
   MIDTRANS_API_KEY_PRIMARY=new_key
   # Remove MIDTRANS_API_KEY_SECONDARY
   ```

6. **Revoke old key** in gateway dashboard

---

## Webhook Secret Rotation

### Procedure

1. **Generate new secret**:
   ```bash
   openssl rand -base64 32
   ```

2. **Update gateway webhook configuration** with new secret

3. **Update environment**:
   ```env
   WEBHOOK_SECRET_CURRENT=old_secret
   WEBHOOK_SECRET_NEW=new_secret
   ```

4. **Update webhook verification** to accept both:
   ```php
   $validSignatures = [
       hash_hmac('sha256', $payload, config('webhook.current_secret')),
       hash_hmac('sha256', $payload, config('webhook.new_secret')),
   ];
   
   if (!in_array($receivedSignature, $validSignatures)) {
       abort(403);
   }
   ```

5. **Deploy to production**

6. **Wait 48 hours** (allow for retry delays)

7. **Remove old secret**:
   ```env
   WEBHOOK_SECRET=new_secret
   ```

---

## Encryption Key Rotation (File Storage)

### Procedure

**WARNING**: This requires re-encrypting all stored files.

1. **Generate new key**:
   ```bash
   php artisan key:generate-storage
   ```

2. **Add to environment**:
   ```env
   STORAGE_ENCRYPTION_KEY=new_key
   STORAGE_ENCRYPTION_KEY_OLD=old_key
   ```

3. **Run re-encryption job**:
   ```bash
   php artisan storage:reencrypt --key=old --new-key=new
   ```

4. **Monitor progress**:
   ```bash
   php artisan queue:work --queue=reencryption --verbose
   ```

5. **Verify re-encryption**:
   ```bash
   php artisan storage:verify-encryption
   ```

6. **Remove old key** (after all files re-encrypted):
   ```env
   # Remove STORAGE_ENCRYPTION_KEY_OLD
   ```

---

## SSH Key Rotation (Production Servers)

### Procedure

1. **Generate new SSH key pair**:
   ```bash
   ssh-keygen -t ed25519 -C "rekber-prod-2026"
   ```

2. **Add new public key** to all servers:
   ```bash
   ssh-copy-id -i ~/.ssh/rekber-prod-2026.pub deployer@server
   ```

3. **Update CI/CD** with new private key:
   - GitHub Actions: Update secret `SSH_PRIVATE_KEY`
   - Store in encrypted vault

4. **Test deployment** with new key:
   ```bash
   ssh -i ~/.ssh/rekber-prod-2026 deployer@server
   ```

5. **Remove old public key** from servers (after 30 days):
   ```bash
   # Edit ~/.ssh/authorized_keys
   # Remove old key line
   ```

6. **Revoke old private key**:
   - Delete from local machine
   - Remove from password manager
   - Update documentation

---

## Emergency Rotation

### When to Rotate Immediately

1. Key exposed in public repository
2. Suspected compromise (unusual activity)
3. Employee departure (with access to keys)
4. Security audit finding
5. Regulatory requirement

### Emergency Rotation Checklist

- [ ] Identify compromised secret(s)
- [ ] Assess blast radius (what can attacker access?)
- [ ] Generate new secret immediately
- [ ] Update production environment (no grace period)
- [ ] Revoke old secret immediately
- [ ] Monitor for unauthorized access attempts
- [ ] Review audit logs for suspicious activity
- [ ] Notify security team
- [ ] Document incident

---

## Automation

### Scheduled Reminders

Cron job sends reminder 14 days before rotation due:
```bash
# /etc/cron.d/key-rotation-reminder
0 9 * * 1 /usr/bin/php /var/www/rekber/artisan key:rotation-reminder
```

### Rotation Tracking

Store rotation history:
```sql
CREATE TABLE key_rotations (
    id BIGSERIAL PRIMARY KEY,
    key_type VARCHAR(50),
    rotated_at TIMESTAMP,
    rotated_by VARCHAR(100),
    reason TEXT,
    next_rotation_due TIMESTAMP
);
```

---

## Compliance

### Regulatory Requirements

- **PCI-DSS**: Rotate encryption keys annually
- **ISO 27001**: Document key rotation procedures
- **SOC 2**: Maintain key rotation audit trail
- **BI/OJK**: Secure key storage and access control

### Audit Evidence

- Key rotation log (who, when, why)
- Verification that old keys revoked
- Access control changes
- Post-rotation testing results

---

## Best Practices

1. **Never commit secrets** to version control
2. **Use strong passphrases** for generated keys (min 32 characters)
3. **Store secrets** in encrypted vault (e.g., AWS Secrets Manager, HashiCorp Vault)
4. **Limit access** to production secrets (need-to-know basis)
5. **Log all rotations** for audit trail
6. **Test rollback** procedure before rotation
7. **Automate where possible** to reduce human error
8. **Rotate immediately** if compromise suspected

---

## Contact

For key rotation questions:
- Primary: DevOps Lead
- Secondary: Security Officer
- Emergency: CTO
