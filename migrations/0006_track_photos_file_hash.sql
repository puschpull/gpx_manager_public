-- ============================================================
--  0006_track_photos_file_hash.sql — Add track_photos.file_hash column + unique index
--  MySQL 5.7 compatible: information_schema check pattern
-- ============================================================

SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'track_photos' AND COLUMN_NAME = 'file_hash');
SET @sql := IF(@x = 0, 'ALTER TABLE track_photos ADD COLUMN file_hash VARCHAR(40) DEFAULT NULL AFTER file_size', 'SELECT "exists" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @y := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'track_photos' AND INDEX_NAME = 'idx_tp_hash');
SET @sql2 := IF(@y = 0, 'ALTER TABLE track_photos ADD UNIQUE INDEX idx_tp_hash (file_hash)', 'SELECT "exists" AS info');
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;
