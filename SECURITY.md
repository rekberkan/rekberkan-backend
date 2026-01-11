# Security Policy

## Supported Versions

We release security updates for the following versions:

| Version | Supported          |
| ------- | ------------------ |
| 1.x.x   | :white_check_mark: |
| < 1.0   | :x:                |

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues.**

Instead, please report them via email to: **security@rekberkan.com**

You should receive a response within 48 hours. If for some reason you do not, please follow up via email to ensure we received your original message.

Please include the following information:

- Type of vulnerability
- Full paths of source file(s) related to the vulnerability
- Location of the affected source code (tag/branch/commit or direct URL)
- Step-by-step instructions to reproduce the issue
- Proof-of-concept or exploit code (if possible)
- Impact of the issue, including how an attacker might exploit it

## Security Best Practices

### For Developers

1. **Never commit secrets**
   - Use `.env` files (not committed)
   - Rotate secrets every 90 days
   - Use strong, randomly generated keys

2. **Webhook Security**
   - Always verify signatures
   - Validate IP addresses
   - Implement replay attack prevention
   - Log all webhook attempts

3. **Authentication & Authorization**
   - Implement rate limiting
   - Use secure session management
   - Validate tenant ownership
   - Cache authorization checks

4. **Input Validation**
   - Use FormRequest classes
   - Validate all user inputs
   - Sanitize data before storage
   - Use parameterized queries

5. **Logging**
   - Log security events
   - Don't log sensitive data
   - Use appropriate log levels
   - Monitor logs regularly

### For Deployment

1. **Environment Configuration**
   ```bash
   APP_DEBUG=false
   APP_ENV=production
   LOG_LEVEL=warning
   ```

2. **Rate Limiting**
   - Enable on all endpoints
   - Configure per operation type
   - Monitor rate limit hits

3. **Geo-Blocking**
   ```bash
   RISK_GEO_ENABLED=true
   RISK_BLOCKED_COUNTRIES=KP,IR,SY,CU
   ```

4. **Webhook Security**
   ```bash
   XENDIT_VERIFY_IP=true
   XENDIT_WEBHOOK_SECRET=<strong-secret>
   ```

5. **Database**
   - Use strong passwords
   - Enable SSL/TLS connections
   - Restrict network access
   - Regular backups

6. **Monitoring**
   - Set up Sentry or similar
   - Monitor failed auth attempts
   - Alert on suspicious activity
   - Regular security audits

## Security Features

### Implemented

- ✅ Webhook signature verification (HMAC SHA-256/SHA-512)
- ✅ IP whitelist validation
- ✅ Rate limiting on all endpoints
- ✅ Idempotency with secure keys (ULID)
- ✅ Tenant isolation and validation
- ✅ Request validation on all operations
- ✅ Admin authorization caching
- ✅ Replay attack prevention
- ✅ Secure error handling
- ✅ Comprehensive security logging

### Planned

- 🔄 Two-factor authentication (2FA)
- 🔄 Anomaly detection
- 🔄 Advanced fraud detection
- 🔄 Security headers middleware
- 🔄 API key rotation automation

## Security Incidents

### Response Process

1. **Detection**: Identify security incident
2. **Assessment**: Evaluate severity and impact
3. **Containment**: Isolate affected systems
4. **Eradication**: Remove threat
5. **Recovery**: Restore normal operations
6. **Lessons Learned**: Document and improve

### Contact

- **Security Team**: security@rekberkan.com
- **Emergency Hotline**: +62-xxx-xxxx-xxxx
- **PGP Key**: Available on request

## Acknowledgments

We thank the following researchers for responsibly disclosing vulnerabilities:

- [List will be updated as we receive reports]

## Updates

This security policy is reviewed quarterly and updated as needed.

**Last Updated**: January 11, 2026
