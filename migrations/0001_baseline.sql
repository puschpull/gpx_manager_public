-- ============================================================
--  0001_baseline.sql — Baseline schema (all core tables)
--  Matches install.sql exactly; safe to run on empty DB.
--  Uses CREATE TABLE IF NOT EXISTS — idempotent.
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

CREATE TABLE IF NOT EXISTS tracks (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    filename          VARCHAR(255) NOT NULL,
    file_hash         VARCHAR(40)  DEFAULT NULL,
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
    INDEX idx_tracks_date          (date_start)
    -- Note: idx_tracks_distance_km, idx_tracks_ascent, idx_tracks_elevation_max,
    --       idx_tracks_speed_max, idx_tracks_duration added by 0012_indexes.sql
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(255) NOT NULL,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS track_categories (
    track_id    INT NOT NULL,
    category_id INT NOT NULL,
    PRIMARY KEY (track_id, category_id),
    FOREIGN KEY (track_id)    REFERENCES tracks(id)     ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    file_hash     VARCHAR(40)      DEFAULT NULL,
    caption       TEXT             DEFAULT NULL,
    img_direction FLOAT            DEFAULT NULL,
    visible       TINYINT(1)       NOT NULL DEFAULT 1,
    created_at    TIMESTAMP        DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_tp_hash   (file_hash),
    INDEX idx_tp_track  (track_id),
    INDEX idx_tp_taken  (taken_at),
    INDEX idx_tp_coords (lat, lon),
    FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_config (
    config_key   VARCHAR(100) PRIMARY KEY,
    config_value TEXT         DEFAULT NULL,
    updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS filter_presets (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description TEXT         DEFAULT NULL,
    settings    JSON         NOT NULL,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    success      TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_la_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO app_config (config_key, config_value) VALUES
('allowed_themes', '["classic","dark","darkblue","darkgreen","blue","green","minimal","lightgray","brown"]'),
('allowed_langs',  '["cs","en","de","sk","es","fr","pl","it"]'),
('visible_pages',  '["stats","calendar","heatmap","map_search","nearby","filter","compare","settings"]');

SET foreign_key_checks = 1;
