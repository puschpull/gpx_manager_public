-- ============================================================
--  0004_activity_type.sql — Add tracks.activity_type column + index
--  MySQL 5.7 compatible: information_schema check pattern
-- ============================================================

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tracks' AND COLUMN_NAME = 'activity_type');
SET @sql := IF(@x = 0, 'ALTER TABLE tracks ADD COLUMN activity_type VARCHAR(20) DEFAULT NULL AFTER difficulty', 'SELECT "exists" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @y := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tracks' AND INDEX_NAME = 'idx_tracks_activity_type');
SET @sql2 := IF(@y = 0, 'CREATE INDEX idx_tracks_activity_type ON tracks (activity_type)', 'SELECT "exists" AS info');
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;
