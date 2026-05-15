-- ============================================================
--  0008_track_photos_img_direction.sql — Add track_photos.img_direction column
--  MySQL 5.7 compatible: information_schema check pattern
-- ============================================================

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'track_photos' AND COLUMN_NAME = 'img_direction');
SET @sql := IF(@x = 0, 'ALTER TABLE track_photos ADD COLUMN img_direction FLOAT DEFAULT NULL AFTER caption', 'SELECT "exists" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
