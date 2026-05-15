-- ============================================================
--  0014_centroid.sql — Add centroid_lat / centroid_lon columns
--  MySQL 5.7 / MariaDB 10.3 compatible.
--  Uses information_schema check pattern (idempotent).
--
--  Covers: DB-9, DB-10 (N+1 nearby/similar queries)
--
--  Rationale:
--    bounds JSON cannot be indexed; centroid_lat/lon as plain DOUBLE
--    columns allow a composite index used by BBOX pre-filters in
--    nearby_data.php and detail_data.php similar-tracks logic.
--    Populated by: migrations/0014_centroid_backfill.php (run once
--    after this migration). New imports populate centroid via import.php.
-- ============================================================

-- ------------------------------------------------------------
--  tracks.centroid_lat DOUBLE DEFAULT NULL
-- ------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tracks'
      AND COLUMN_NAME  = 'centroid_lat'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE tracks ADD COLUMN centroid_lat DOUBLE DEFAULT NULL AFTER bounds',
    'SELECT "tracks.centroid_lat already exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
--  tracks.centroid_lon DOUBLE DEFAULT NULL
-- ------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tracks'
      AND COLUMN_NAME  = 'centroid_lon'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE tracks ADD COLUMN centroid_lon DOUBLE DEFAULT NULL AFTER centroid_lat',
    'SELECT "tracks.centroid_lon already exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
--  Composite index for BBOX pre-filter queries
--  (nearby_data.php, detail_data.php similar tracks)
-- ------------------------------------------------------------
SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tracks'
      AND INDEX_NAME   = 'idx_tracks_centroid'
);
SET @sql := IF(@idx_exists = 0,
    'CREATE INDEX idx_tracks_centroid ON tracks (centroid_lat, centroid_lon)',
    'SELECT "idx_tracks_centroid already exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
