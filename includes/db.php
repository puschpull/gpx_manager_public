<?php
declare(strict_types=1);

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

require_once __DIR__ . '/app_config.php';
init_app_config();
