-- ============================================================
--  0016_filter_presets.sql — Presety filtrace tras
--  MySQL 5.7 / MariaDB 10.3 compatible. Idempotentní.
--
--  Tabulka dosud vznikala za běhu přes CREATE TABLE IF NOT EXISTS
--  v includes/filter_data.php (na každém AJAX requestu) — přesunuto
--  do migrace (pravidlo po TASK-09: žádné runtime DDL).
--  Na existujících instalacích tabulka už existuje → no-op.
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS filter_presets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,
    settings JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
