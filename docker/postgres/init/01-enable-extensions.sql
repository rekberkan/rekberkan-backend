-- Enable required PostgreSQL extensions
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";

-- Create schema for tenant isolation
CREATE SCHEMA IF NOT EXISTS tenants;

-- Grant permissions
GRANT USAGE ON SCHEMA tenants TO rekberkan;
GRANT ALL PRIVILEGES ON SCHEMA tenants TO rekberkan;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA tenants TO rekberkan;
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA tenants TO rekberkan;

-- Set default privileges for future objects
ALTER DEFAULT PRIVILEGES IN SCHEMA tenants GRANT ALL ON TABLES TO rekberkan;
ALTER DEFAULT PRIVILEGES IN SCHEMA tenants GRANT ALL ON SEQUENCES TO rekberkan;
