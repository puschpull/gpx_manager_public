-- ============================================================
--  0011_schema_migrations.sql — Create schema_migrations tracking table
--  Records which migrations have been applied.
--  migrate.php creates this table before running any migration,
--  so this file is here for documentation / manual inspection.
-- ============================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
    migration    VARCHAR(100) PRIMARY KEY,
    applied_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
