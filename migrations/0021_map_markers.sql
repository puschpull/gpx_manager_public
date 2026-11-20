-- ============================================================
--  0021_map_markers.sql — značky s poznámkou na stránce Mapa
--  MySQL 5.7 / MariaDB 10.3 compatible. Idempotentní, jen přidává tabulku.
--
--  map_markers = místa, která si majitel označí při hledání v mapách
--                (název, poznámka, symbol). Zapisuje jen admin
--                (api/markers/save.php, delete.php); návštěvník vidí
--                jen značky s is_public = 1 (api/markers/list.php).
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS map_markers (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lat         DECIMAL(9,6)  NOT NULL,
    lon         DECIMAL(9,6)  NOT NULL,
    title       VARCHAR(120)  NOT NULL,
    note        TEXT          DEFAULT NULL,
    -- klíč symbolu z api/markers/save.php (pin, star, question, ruin, water, cross, camera, flag)
    symbol      VARCHAR(16)   NOT NULL DEFAULT 'pin',
    is_public   TINYINT(1)    NOT NULL DEFAULT 0,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mm_public (is_public)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
