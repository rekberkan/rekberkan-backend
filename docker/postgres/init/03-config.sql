-- PostgreSQL configuration for financial workloads

-- Enable Row Level Security by default (applied per-table in migrations)
ALTER DATABASE rekberkan SET row_security = on;

-- Logging configuration for audit trail
ALTER DATABASE rekberkan SET log_statement = 'mod';
ALTER DATABASE rekberkan SET log_min_duration_statement = 1000;

-- Connection limits
ALTER DATABASE rekberkan SET max_connections = 100;

-- Statement timeout (60 seconds)
ALTER DATABASE rekberkan SET statement_timeout = '60s';

-- Lock timeout (30 seconds)
ALTER DATABASE rekberkan SET lock_timeout = '30s';

-- Timezone
ALTER DATABASE rekberkan SET timezone = 'Asia/Jakarta';

SELECT 'Database configuration applied';
