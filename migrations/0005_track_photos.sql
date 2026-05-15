-- ============================================================
--  0005_track_photos.sql — Ensure track_photos table exists
--  Baseline (0001) already creates it with full schema.
--  This migration is a sanity check for installs that had
--  the table created by the old auto-migration in db.php
--  (which lacked caption, img_direction, visible columns).
--  CREATE TABLE IF NOT EXISTS is idempotent and safe.
-- ============================================================

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
