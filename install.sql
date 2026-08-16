-- ============================================================
--  GPX Manager — instalační SQL skript
--  Verze: 2026-07 (migrations 0012–0017 synced)
--  Spusť tento soubor přes phpMyAdmin nebo MySQL CLI
--  PŘED spuštěním: vytvoř prázdnou databázi (např. gpx_manager)
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ------------------------------------------------------------
--  Tabulka: tracks (GPX trasy)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tracks (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    filename          VARCHAR(255) NOT NULL,
    file_hash         VARCHAR(64)  DEFAULT NULL,
    track_name        VARCHAR(255) DEFAULT NULL,
    alt_title         VARCHAR(255) DEFAULT NULL,
    note              TEXT         DEFAULT NULL,
    color             VARCHAR(20)  DEFAULT NULL,
    device            VARCHAR(100) DEFAULT NULL,
    date_start        DATETIME     DEFAULT NULL,
    date_end          DATETIME     DEFAULT NULL,
    duration          INT          DEFAULT NULL,
    moving_time       INT          DEFAULT NULL,
    stopped_time      INT          DEFAULT NULL,
    distance_km       FLOAT        DEFAULT NULL,
    ascent            FLOAT        DEFAULT NULL,
    descent           FLOAT        DEFAULT NULL,
    elevation_min     FLOAT        DEFAULT NULL,
    elevation_max     FLOAT        DEFAULT NULL,
    speed_max         FLOAT        DEFAULT NULL,
    speed_avg         FLOAT        DEFAULT NULL,
    speed_avg_total   FLOAT        DEFAULT NULL,
    avg_ascent_rate   FLOAT        DEFAULT NULL,
    avg_descent_rate  FLOAT        DEFAULT NULL,
    max_ascent_rate   FLOAT        DEFAULT NULL,
    max_descent_rate  FLOAT        DEFAULT NULL,
    bounds            JSON         DEFAULT NULL,
    centroid_lat      DOUBLE       DEFAULT NULL,
    centroid_lon      DOUBLE       DEFAULT NULL,
    trackpoints_count INT          DEFAULT NULL,
    is_favorite       TINYINT(1)   NOT NULL DEFAULT 0,
    difficulty        TINYINT UNSIGNED DEFAULT NULL,
    activity_type     VARCHAR(20)  DEFAULT NULL,
    created_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_filename (filename),
    UNIQUE KEY uq_hash     (file_hash),
    INDEX idx_tracks_favorite      (is_favorite),
    INDEX idx_tracks_difficulty    (difficulty),
    INDEX idx_tracks_activity_type (activity_type),
    INDEX idx_tracks_date          (date_start),
    -- 0012_indexes: stats.php records queries (ORDER BY col DESC LIMIT 1)
    INDEX idx_tracks_distance_km   (distance_km),
    INDEX idx_tracks_ascent        (ascent),
    INDEX idx_tracks_elevation_max (elevation_max),
    INDEX idx_tracks_speed_max     (speed_max),
    INDEX idx_tracks_duration      (duration),
    -- 0014_centroid: composite index for BBOX pre-filter (nearby, similar tracks)
    INDEX idx_tracks_centroid      (centroid_lat, centroid_lon)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  Tabulka: categories (kategorie tras)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(255) NOT NULL,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  Tabulka: track_categories (vazba N:M trasy ↔ kategorie)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS track_categories (
    track_id    INT NOT NULL,
    category_id INT NOT NULL,
    PRIMARY KEY (track_id, category_id),
    FOREIGN KEY (track_id)    REFERENCES tracks(id)     ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    -- 0012_indexes: supports EXISTS subquery driven from categories side
    INDEX idx_tc_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  Tabulka: track_photos (fotografie přiřazené k trasám)
--  Poznámka: vytváří se automaticky i při prvním spuštění PHP
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS track_photos (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    track_id      INT              DEFAULT NULL,
    filename      VARCHAR(255)     NOT NULL,
    orig_name     VARCHAR(255)     DEFAULT NULL,
    lat           DOUBLE           DEFAULT NULL,
    lon           DOUBLE           DEFAULT NULL,
    altitude      FLOAT            DEFAULT NULL,
    taken_at      DATETIME         DEFAULT NULL,
    width         SMALLINT UNSIGNED DEFAULT NULL,
    height        SMALLINT UNSIGNED DEFAULT NULL,
    file_size     INT UNSIGNED     DEFAULT NULL,
    file_hash     VARCHAR(64)      DEFAULT NULL,
    caption       TEXT             DEFAULT NULL,
    img_direction FLOAT            DEFAULT NULL,
    visible       TINYINT(1)       NOT NULL DEFAULT 1,
    created_at    TIMESTAMP        DEFAULT CURRENT_TIMESTAMP,
    -- 0015_virtual_tracks: vazba fotka → virtuální trasa (nezávislá na track_id)
    virtual_track_id INT           DEFAULT NULL,
    UNIQUE KEY idx_tp_hash   (file_hash),
    INDEX idx_tp_track  (track_id),
    INDEX idx_tp_taken  (taken_at),
    INDEX idx_tp_coords (lat, lon),
    -- 0012_indexes: composite index for photo_count correlated subquery
    INDEX idx_tp_track_visible (track_id, visible),
    INDEX idx_tp_vtrack (virtual_track_id),
    FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE SET NULL,
    CONSTRAINT fk_tp_vtrack FOREIGN KEY (virtual_track_id) REFERENCES virtual_tracks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  Tabulka: virtual_tracks (virtuální trasy z GPS fotek — 0015)
--  Trasa tvořená body z fotek, ne z GPX. Uložena samostatně, ne v `tracks`.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS virtual_tracks (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(255) DEFAULT NULL,
    note          TEXT         DEFAULT NULL,
    color         VARCHAR(20)  DEFAULT NULL,
    is_favorite   TINYINT(1)   NOT NULL DEFAULT 0,
    date_start    DATETIME     DEFAULT NULL,
    date_end      DATETIME     DEFAULT NULL,
    photo_count   INT          DEFAULT 0,
    distance_km   FLOAT        DEFAULT NULL,
    ascent        FLOAT        DEFAULT NULL,
    descent       FLOAT        DEFAULT NULL,
    bounds        JSON         DEFAULT NULL,
    centroid_lat  DOUBLE       DEFAULT NULL,
    centroid_lon  DOUBLE       DEFAULT NULL,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vt_date     (date_start),
    INDEX idx_vt_centroid (centroid_lat, centroid_lon)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
--  Tabulka: app_config (globální konfigurace aplikace)
--  Poznámka: vytváří se automaticky i při prvním spuštění PHP
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_config (
    config_key   VARCHAR(100) PRIMARY KEY,
    config_value TEXT         DEFAULT NULL,
    updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  Tabulka: filter_presets (uložené filtry tras)
--  Poznámka: shodné s migrations/0016_filter_presets.sql
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS filter_presets (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description TEXT         DEFAULT NULL,
    settings    JSON         NOT NULL,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  Tabulka: planned_routes (uložené plány z Plánovače výšlapu)
--  Poznámka: shodné s migrations/0017_planned_routes.sql
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS planned_routes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    profile     VARCHAR(30)  NOT NULL DEFAULT 'foot_hiking',
    plan_date   DATE         DEFAULT NULL,
    track_id    INT          DEFAULT NULL,
    waypoints   JSON         NOT NULL,
    geometry    MEDIUMTEXT   DEFAULT NULL,
    length_m    INT          DEFAULT NULL,
    duration_s  INT          DEFAULT NULL,
    ascent      INT          DEFAULT NULL,
    descent     INT          DEFAULT NULL,
    note        TEXT         DEFAULT NULL,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pr_date (plan_date),
    INDEX idx_pr_track (track_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
--  Tabulka: login_attempts (IP-based brute-force protection)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    success      TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_la_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
--  Výchozí konfigurace aplikace
-- ------------------------------------------------------------
-- Pozn.: seznamy musí odpovídat all_langs() / all_pages() v includes/app_constants.php
-- (allowed_themes odstraněno — barevná témata zrušena 6/2026, zůstal světlý/tmavý režim)
INSERT IGNORE INTO app_config (config_key, config_value) VALUES
('allowed_langs',  '["cs","en","de","sk","es","fr","pl","it"]'),
('visible_pages',  '["stats","calendar","heatmap","photo_heatmap","map_search","nearby","photo_nearby","filter","compare","settings","virtual_tracks"]');

SET foreign_key_checks = 1;

-- ============================================================
--  Hotovo! Pokračuj spuštěním setup.php v prohlížeči nebo
--  ručně vyplň soubor .env (viz .env.example).
-- ============================================================
