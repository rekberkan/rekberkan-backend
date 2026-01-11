# Deployment Checklist

## Pre-Deployment

### ✅ Environment Configuration

```bash
# Copy environment file
cp .env.example .env

# Generate app key
php artisan key:generate

# Configure database
DB_CONNECTION=pgsql
DB_HOST=your-db-host
DB_DATABASE=rekberkan
DB_USERNAME=your-username
DB_PASSWORD=your-password

# Configure Redis
REDIS_HOST=your-redis-host
REDIS_PASSWORD=your-redis-password

# Configure Xendit
XENDIT_API_KEY=xnd_...
XENDIT_WEBHOOK_SECRET=...
XENDIT_VERIFY_IP=true
```

### ✅ Database Setup

```bash
# Run migrations
php artisan migrate

# Seed initial data (optional)
php artisan db:seed
```

### ✅ Security Configuration

```bash
# Set production environment
APP_ENV=production
APP_DEBUG=false

# Enable CSP strict mode
CSP_MODE=production

# Configure rate limiting
RATE_LIMIT_DEPOSIT=10
RATE_LIMIT_WITHDRAWAL=5
RATE_LIMIT_ESCROW=20
```

### ✅ Caching & Optimization

```bash
# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Optimize for production
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### ✅ Queue & Scheduler Setup

```bash
# Start queue worker (use supervisor in production)
php artisan queue:work --daemon

# Add to crontab
* * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1
```

### ✅ Membership Auto-Renewal

Add to `app/Console/Kernel.php`:
```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('memberships:renew')->daily();
}
```

### ✅ Admin User Setup

```sql
-- Create admin user
UPDATE users 
SET role = 'admin', is_admin = true 
WHERE email = 'admin@rekberkan.com';
```

### ✅ Monitoring

```bash
# Install Sentry (optional)
composer require sentry/sentry-laravel

# Configure in .env
SENTRY_LARAVEL_DSN=your-dsn
```

## Post-Deployment Testing

```bash
# Health check
curl https://your-domain.com/api/health

# Test authentication
curl -X POST https://your-domain.com/api/v1/auth/login \
  -d '{"email": "test@example.com", "password": "password"}'

# Test webhook (from Xendit dashboard)
# Configure callback URL: https://your-domain.com/api/webhooks/xendit/deposit
```

## Rollback Plan

```bash
# Rollback migrations
php artisan migrate:rollback --step=1

# Rollback code
git checkout main
git pull
php artisan config:cache
php artisan route:cache
```
