-- ============================================================
--  0009_track_photos_visible.sql — Add track_photos.visible column
--  MySQL 5.7 compatible: information_schema check pattern
-- ============================================================

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'track_photos' AND COLUMN_NAME = 'visible');
SET @sql := IF(@x = 0, 'ALTER TABLE track_photos ADD COLUMN visible TINYINT(1) NOT NULL DEFAULT 1 AFTER img_direction', 'SELECT "exists" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
