-- ============================================================
--  0002_is_favorite.sql — Add tracks.is_favorite column + index
--  MySQL 5.7 compatible: information_schema check pattern
-- ============================================================

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tracks' AND COLUMN_NAME = 'is_favorite');
SET @sql := IF(@x = 0, 'ALTER TABLE tracks ADD COLUMN is_favorite TINYINT(1) NOT NULL DEFAULT 0 AFTER trackpoints_count', 'SELECT "exists" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @y := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tracks' AND INDEX_NAME = 'idx_tracks_favorite');
SET @sql2 := IF(@y = 0, 'CREATE INDEX idx_tracks_favorite ON tracks (is_favorite)', 'SELECT "exists" AS info');
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;
