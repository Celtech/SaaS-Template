-- Enable pg_stat_statements for query performance monitoring
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;

-- Enable uuid-ossp for native UUID generation (UUID v7 handled in app layer)
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- pgaudit for SOC2 audit logging at the database level.
-- Requires the pgaudit package installed on the Postgres host.
-- In production (postgres:17 Debian image), install postgresql-17-pgaudit.
-- In dev (postgres:17-alpine), pgaudit is not available and this is skipped.
DO $$
BEGIN
    CREATE EXTENSION IF NOT EXISTS pgaudit;
EXCEPTION WHEN OTHERS THEN
    RAISE NOTICE 'pgaudit not available: %. Install pgaudit in production.', SQLERRM;
END;
$$;
