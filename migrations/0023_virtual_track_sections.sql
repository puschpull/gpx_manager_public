-- ============================================================
--  0023_virtual_track_sections.sql — úseky virtuální trasy v databázi
--  MySQL 5.7 / MariaDB 10.3 compatible. Idempotentní, jen přidává sloupec.
--
--  virtual_tracks.sections = JSON s úseky, které admin zadal na detailu
--  virtuální trasy (hranice mezi fotkami + profil trasování každého úseku):
--  {"boundaries":[…], "starts":[…], "profiles":[…]}.
--  Dřív se ukládaly jen do localStorage prohlížeče (jiný počítač je neviděl).
--  NULL = úseky nezadané. TEXT, ne JSON — kvůli MariaDB 10.3.
-- ============================================================

SET NAMES utf8mb4;

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'virtual_tracks'
      AND COLUMN_NAME  = 'sections'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE virtual_tracks ADD COLUMN sections TEXT DEFAULT NULL AFTER note',
    'SELECT "virtual_tracks.sections already exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
