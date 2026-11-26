-- ============================================================
--  0022_collections.sql — kolekce tras (vlastní seznamy napříč kategoriemi)
--  MySQL 5.7 / MariaDB 10.3 compatible. Idempotentní, jen přidává tabulky.
--
--  collections       = pojmenovaný seznam tras („Lužické hory 2026“, „Rozhledny“),
--                      is_public = 1 → vidí ho i návštěvník (když je stránka
--                      'collections' ve visible_pages)
--  collection_tracks = vazba N:M kolekce ↔ trasa; smazáním trasy nebo kolekce
--                      zmizí i vazba (ON DELETE CASCADE)
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS collections (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(160)  NOT NULL,
    description  TEXT          DEFAULT NULL,
    is_public    TINYINT(1)    NOT NULL DEFAULT 0,
    created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_col_public (is_public)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collection_tracks (
    collection_id  INT UNSIGNED NOT NULL,
    track_id       INT          NOT NULL,
    position       INT          NOT NULL DEFAULT 0,
    added_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (collection_id, track_id),
    INDEX idx_ct_track (track_id),
    CONSTRAINT fk_ct_collection FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE,
    CONSTRAINT fk_ct_track      FOREIGN KEY (track_id)      REFERENCES tracks(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
