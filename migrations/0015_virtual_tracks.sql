-- ============================================================
--  0015_virtual_tracks.sql — Virtuální trasy z fotek
--  MySQL 5.7 / MariaDB 10.3 compatible. Idempotentní.
--
--  Virtuální trasa = trasa tvořená body z GPS fotek (ne GPX),
--  vzniklá chytrým shluknutím nepřiřazených fotek podle času i polohy.
--  Uložena v samostatné tabulce (NE v `tracks`).
--
--  - virtual_tracks: hlavní tabulka virtuálních tras
--  - track_photos.virtual_track_id: vazba fotka → virtuální trasa
--    (nezávislá na track_id; fotka ve virt. trase má track_id = NULL)
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
--  Tabulka virtual_tracks
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS virtual_tracks (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(255) DEFAULT NULL,
    note          TEXT         DEFAULT NULL,
    color         VARCHAR(20)  DEFAULT NULL,
    is_favorite   TINYINT(1)   NOT NULL DEFAULT 0,
    date_start    DATETIME     DEFAULT NULL,
    date_end      DATETIME     DEFAULT NULL,
    photo_count   INT          DEFAULT 0,
    distance_km   FLOAT        DEFAULT NULL,
    ascent        FLOAT        DEFAULT NULL,
    descent       FLOAT        DEFAULT NULL,
    bounds        JSON         DEFAULT NULL,
    centroid_lat  DOUBLE       DEFAULT NULL,
    centroid_lon  DOUBLE       DEFAULT NULL,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vt_date     (date_start),
    INDEX idx_vt_centroid (centroid_lat, centroid_lon)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
--  track_photos.virtual_track_id INT DEFAULT NULL (po track_id)
-- ------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'track_photos'
      AND COLUMN_NAME  = 'virtual_track_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE track_photos ADD COLUMN virtual_track_id INT DEFAULT NULL AFTER track_id',
    'SELECT "track_photos.virtual_track_id already exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
--  Index na virtual_track_id
-- ------------------------------------------------------------
SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'track_photos'
      AND INDEX_NAME   = 'idx_tp_vtrack'
);
SET @sql := IF(@idx_exists = 0,
    'CREATE INDEX idx_tp_vtrack ON track_photos (virtual_track_id)',
    'SELECT "idx_tp_vtrack already exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
--  FK track_photos.virtual_track_id → virtual_tracks.id (ON DELETE SET NULL)
-- ------------------------------------------------------------
SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA    = DATABASE()
      AND TABLE_NAME      = 'track_photos'
      AND CONSTRAINT_NAME = 'fk_tp_vtrack'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE track_photos ADD CONSTRAINT fk_tp_vtrack FOREIGN KEY (virtual_track_id) REFERENCES virtual_tracks(id) ON DELETE SET NULL',
    'SELECT "fk_tp_vtrack already exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
