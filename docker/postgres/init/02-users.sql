-- Create read-only user for analytics/reporting
-- Separation of concerns: application user vs reporting user

-- Application user (already created by POSTGRES_USER)
-- Read-only reporting user
CREATE USER rekberkan_readonly WITH PASSWORD 'readonly_change_me';

-- Grant connect
GRANT CONNECT ON DATABASE rekberkan TO rekberkan_readonly;

-- Grant schema usage
GRANT USAGE ON SCHEMA public TO rekberkan_readonly;

-- Grant select on all current tables
GRANT SELECT ON ALL TABLES IN SCHEMA public TO rekberkan_readonly;

-- Grant select on all future tables
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO rekberkan_readonly;

SELECT 'Database users configured';
