-- ============================================================
--  0007_track_photos_caption.sql — Add track_photos.caption column
--  MySQL 5.7 compatible: information_schema check pattern
-- ============================================================

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'track_photos' AND COLUMN_NAME = 'caption');
SET @sql := IF(@x = 0, 'ALTER TABLE track_photos ADD COLUMN caption TEXT DEFAULT NULL AFTER file_hash', 'SELECT "exists" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
