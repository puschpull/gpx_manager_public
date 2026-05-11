<?php
require_once __DIR__ . '/../config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    error_log("db.php connection: " . $e->getMessage());
    die(APP_ENV === 'local' ? "Database connection failed: " . $e->getMessage() : "Chyba připojení k databázi.");
}

/* ===== Auto-migrace: is_favorite sloupec ===== */
try {
    $pdo->query("SELECT is_favorite FROM tracks LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE tracks ADD COLUMN is_favorite TINYINT(1) NOT NULL DEFAULT 0 AFTER trackpoints_count");
    $pdo->exec("CREATE INDEX idx_tracks_favorite ON tracks (is_favorite)");
}

/* ===== Auto-migrace: difficulty sloupec ===== */
try {
    $pdo->query("SELECT difficulty FROM tracks LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE tracks ADD COLUMN difficulty TINYINT UNSIGNED DEFAULT NULL AFTER is_favorite");
    $pdo->exec("CREATE INDEX idx_tracks_difficulty ON tracks (difficulty)");
}

/* ===== Auto-migrace: activity_type sloupec ===== */
try {
    $pdo->query("SELECT activity_type FROM tracks LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE tracks ADD COLUMN activity_type VARCHAR(20) DEFAULT NULL AFTER difficulty");
    $pdo->exec("CREATE INDEX idx_tracks_activity_type ON tracks (activity_type)");
}

/* ===== Auto-migrace: app_config tabulka (globální konfigurace) ===== */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_config (
        config_key VARCHAR(100) PRIMARY KEY,
        config_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    error_log("db.php app_config CREATE TABLE: " . $e->getMessage());
}

require_once __DIR__ . '/app_config.php';
init_app_config();

/* ===== Auto-migrace: track_photos tabulka (fotografie na trasách) ===== */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS track_photos (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        track_id     INT DEFAULT NULL,
        filename     VARCHAR(255) NOT NULL,
        orig_name    VARCHAR(255) DEFAULT NULL,
        lat          DOUBLE DEFAULT NULL,
        lon          DOUBLE DEFAULT NULL,
        altitude     FLOAT DEFAULT NULL,
        taken_at     DATETIME DEFAULT NULL,
        width        SMALLINT UNSIGNED DEFAULT NULL,
        height       SMALLINT UNSIGNED DEFAULT NULL,
        file_size    INT UNSIGNED DEFAULT NULL,
        file_hash    VARCHAR(40) DEFAULT NULL,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE SET NULL,
        INDEX idx_tp_track  (track_id),
        INDEX idx_tp_taken  (taken_at),
        INDEX idx_tp_coords (lat, lon),
        UNIQUE INDEX idx_tp_hash (file_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    error_log("db.php track_photos CREATE TABLE: " . $e->getMessage());
}

/* ===== Auto-migrace: file_hash sloupec v track_photos ===== */
try {
    $pdo->query("SELECT file_hash FROM track_photos LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE track_photos ADD COLUMN file_hash VARCHAR(40) DEFAULT NULL AFTER file_size");
    $pdo->exec("ALTER TABLE track_photos ADD UNIQUE INDEX idx_tp_hash (file_hash)");
}

/* ===== Auto-migrace: caption sloupec v track_photos (B2) ===== */
try {
    $pdo->query("SELECT caption FROM track_photos LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE track_photos ADD COLUMN caption TEXT DEFAULT NULL AFTER file_hash");
}

/* ===== Auto-migrace: img_direction sloupec v track_photos (C2) ===== */
try {
    $pdo->query("SELECT img_direction FROM track_photos LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE track_photos ADD COLUMN img_direction FLOAT DEFAULT NULL AFTER caption");
}

/* ===== Auto-migrace: visible sloupec v track_photos ===== */
try {
    $pdo->query("SELECT visible FROM track_photos LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE track_photos ADD COLUMN visible TINYINT(1) NOT NULL DEFAULT 1 AFTER img_direction");
}
?>