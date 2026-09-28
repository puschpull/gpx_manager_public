-- ============================================================
--  0020_track_stories.sql — Cestopis (text výletu psaný jazykovým modelem)
--  MySQL 5.7 / MariaDB 10.3 compatible. Idempotentní, jen přidává tabulky.
--
--  track_stories       = vygenerované verze cestopisu k trase. Každé volání
--                        API = jeden řádek (i neúspěšné — i to se platí
--                        a počítá do měsíčního stropu). Návštěvník vidí jen
--                        verzi s is_published = 1 (nejvýš jedna na trasu).
--  story_geocode_cache = názvy míst zastávek (Nominatim), ať se neptá znovu
--  story_poi_cache     = pojmenované body z OpenStreetMap (Overpass), 30 dní
--
--  Funkce je volitelná (Administrace → Volitelné funkce → cestopis);
--  bez ní tabulky zůstanou prázdné.
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS track_stories (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    track_id       INT          NOT NULL,
    -- varianta: styl (factual | narrative | literary), model, mapa, fotky (none | rest | all)
    style          VARCHAR(20)  NOT NULL,
    model          VARCHAR(60)  NOT NULL,
    with_map       TINYINT(1)   NOT NULL DEFAULT 0,
    photo_mode     VARCHAR(10)  NOT NULL DEFAULT 'none',
    photos_sent    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    -- průběh: running → done | error; smazaná verze = deleted (text pryč,
    -- řádek zůstává kvůli součtu útraty za měsíc)
    status         VARCHAR(10)  NOT NULL DEFAULT 'running',
    error_message  VARCHAR(500) DEFAULT NULL,
    is_published   TINYINT(1)   NOT NULL DEFAULT 0,
    -- skutečná spotřeba z odpovědi API
    input_tokens   INT UNSIGNED DEFAULT NULL,
    output_tokens  INT UNSIGNED DEFAULT NULL,
    cost_usd       DECIMAL(8,4) DEFAULT NULL,
    duration_s     SMALLINT UNSIGNED DEFAULT NULL,
    -- co přesně model dostal (dohledatelnost každého tvrzení) a výsledný text
    facts_json     JSON         DEFAULT NULL,
    story          MEDIUMTEXT   DEFAULT NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at    TIMESTAMP    NULL DEFAULT NULL,
    INDEX idx_ts_track (track_id, is_published),
    INDEX idx_ts_created (created_at),
    CONSTRAINT fk_ts_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS story_geocode_cache (
    cache_key   VARCHAR(32)  NOT NULL PRIMARY KEY,
    lat         DOUBLE       NOT NULL,
    lon         DOUBLE       NOT NULL,
    -- prázdné = dotaz proběhl, ale služba nic nevrátila (výpadky se neukládají)
    place_name  VARCHAR(160) NOT NULL DEFAULT '',
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS story_poi_cache (
    cache_key   CHAR(32)     NOT NULL PRIMARY KEY,  -- md5 dotazu Overpass
    payload     MEDIUMTEXT   NOT NULL,              -- odpověď Overpass (JSON)
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
