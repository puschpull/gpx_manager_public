-- ============================================================
--  0019_track_place_name.sql — název místa pro titulek trasy
--  MySQL 5.7 / MariaDB 10.3 compatible. Idempotentní
--  (vzor kontroly přes information_schema jako 0014_centroid.sql).
--
--  tracks.place_name = obec / část obce z reverzního geokódování
--  těžiště trasy (Mapy.com). Slouží k sestavení titulku u tras,
--  které mají místo názvu jen časové razítko z přístroje.
--
--  Tři stavy sloupce:
--    NULL  = ještě se nezjišťovalo
--    ''    = zjišťovalo se a nepovedlo se (znovu se už nezkouší)
--    text  = název místa
-- ============================================================

SET NAMES utf8mb4;

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tracks'
      AND COLUMN_NAME  = 'place_name'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE tracks ADD COLUMN place_name VARCHAR(120) DEFAULT NULL AFTER centroid_lon',
    'SELECT "tracks.place_name already exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
