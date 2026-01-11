-- Enable required PostgreSQL extensions
-- This script runs automatically when PostgreSQL container is first created

-- UUID generation
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- Cryptographic functions
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- Text search
CREATE EXTENSION IF NOT EXISTS "pg_trgm";

-- Additional indexing methods
CREATE EXTENSION IF NOT EXISTS "btree_gin";
CREATE EXTENSION IF NOT EXISTS "btree_gist";

-- Logging
SELECT format('PostgreSQL extensions installed at %s', now());
