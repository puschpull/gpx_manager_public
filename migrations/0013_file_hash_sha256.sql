-- ============================================================
--  0013_file_hash_sha256.sql — Extend file_hash to VARCHAR(64)
--  MySQL 5.7 / MariaDB 10.3 compatible.
--  Uses information_schema check pattern (idempotent).
--
--  Covers: DB-2 (SHA-1 → 64-char forward compat)
--
--  Rationale:
--    SHA-1 produces 40 hex chars (fits in VARCHAR(40)).
--    SHA-256 produces 64 hex chars — VARCHAR(40) would silently
--    truncate the hash on insert (strict mode) or corrupt data (lenient).
--    Expanding to VARCHAR(64) is backward-compatible: all existing
--    40-char SHA-1 hashes remain valid.
--
--  NOTE: sha1_file() calls in PHP are NOT changed here — that is a
--  separate task (dedup algorithm change must be coordinated with
--  existing hash values in production data).
-- ============================================================

-- ------------------------------------------------------------
--  tracks.file_hash VARCHAR(40) → VARCHAR(64)
--  Only runs MODIFY if the column is currently narrower than 64.
-- ------------------------------------------------------------
SET @x := (SELECT CHARACTER_MAXIMUM_LENGTH
           FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME   = 'tracks'
             AND COLUMN_NAME  = 'file_hash');
SET @sql := IF(@x IS NULL OR @x < 64,
    'ALTER TABLE tracks MODIFY COLUMN file_hash VARCHAR(64) DEFAULT NULL',
    'SELECT "tracks.file_hash already VARCHAR(64) or wider" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
--  track_photos.file_hash VARCHAR(40) → VARCHAR(64)
-- ------------------------------------------------------------
SET @x := (SELECT CHARACTER_MAXIMUM_LENGTH
           FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME   = 'track_photos'
             AND COLUMN_NAME  = 'file_hash');
SET @sql := IF(@x IS NULL OR @x < 64,
    'ALTER TABLE track_photos MODIFY COLUMN file_hash VARCHAR(64) DEFAULT NULL',
    'SELECT "track_photos.file_hash already VARCHAR(64) or wider" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
