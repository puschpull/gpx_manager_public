-- ============================================================
--  0018_planned_route_track.sql — propojení plánu se skutečnou trasou
--  MySQL 5.7 / MariaDB 10.3 compatible. Idempotentní
--  (vzor kontroly přes information_schema jako 0014_centroid.sql).
--
--  planned_routes.track_id = id trasy, kterou byl plán uskutečněn.
--  NULL = plán zatím nebyl "prošlapán".
--
--  Bez cizího klíče záměrně: tracks.id je INT (signed) a mazání trasy
--  má nechat plán existovat. Osiřelé track_id se pozná spojením
--  přes LEFT JOIN a chová se jako "neuskutečněno".
-- ============================================================

SET NAMES utf8mb4;

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'planned_routes'
      AND COLUMN_NAME  = 'track_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE planned_routes ADD COLUMN track_id INT DEFAULT NULL AFTER plan_date',
    'SELECT "planned_routes.track_id already exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'planned_routes'
      AND INDEX_NAME   = 'idx_pr_track'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE planned_routes ADD INDEX idx_pr_track (track_id)',
    'SELECT "idx_pr_track already exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
