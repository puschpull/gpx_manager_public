-- ============================================================
--  0024_planned_route_public.sql — veřejné / neveřejné plány z Plánovače
--  MySQL 5.7 / MariaDB 10.3 compatible. Idempotentní, jen přidává sloupec.
--
--  planned_routes.is_public = 1 → plán vidí i návštěvník (když je
--  stránka Plánovač ve visible_pages); 0 → jen admin. Nové plány 0.
--  Bezpečnostní kontrola 21. 11. 2026 (S3): ze seznamu plánů s datem do
--  budoucna se dalo vyčíst, kdy majitel nebude doma.
--
--  Při PŘIDÁNÍ sloupce (jen jednou): plány bez data nebo s datem v minulosti
--  zůstanou veřejné (dosud je návštěvník viděl), budoucí se skryjí.
-- ============================================================

SET NAMES utf8mb4;

SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'planned_routes'
      AND COLUMN_NAME  = 'is_public'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE planned_routes ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0 AFTER note',
    'SELECT "planned_routes.is_public already exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@col_exists = 0,
    'UPDATE planned_routes SET is_public = 1 WHERE plan_date IS NULL OR plan_date < CURDATE()',
    'SELECT "planned_routes.is_public: existing values kept" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
