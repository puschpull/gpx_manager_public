-- ============================================================
--  0017_planned_routes.sql — Uložené plány z Plánovače výšlapu
--  MySQL 5.7 / MariaDB 10.3 compatible. Idempotentní.
--
--  waypoints = JSON pole [[lat,lon],...] zadaných bodů
--  geometry  = JSON pole [[lat,lon],...] spočítané trasy (cache,
--              ať načtení plánu nepálí Mapy.com routing kvótu)
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS planned_routes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    profile     VARCHAR(30)  NOT NULL DEFAULT 'foot_hiking',
    plan_date   DATE         DEFAULT NULL,
    waypoints   JSON         NOT NULL,
    geometry    MEDIUMTEXT   DEFAULT NULL,
    length_m    INT          DEFAULT NULL,
    duration_s  INT          DEFAULT NULL,
    ascent      INT          DEFAULT NULL,
    descent     INT          DEFAULT NULL,
    note        TEXT         DEFAULT NULL,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pr_date (plan_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
