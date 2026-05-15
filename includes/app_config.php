<?php
declare(strict_types=1);

/**
 * ===========================================================
 *  app_config.php — Globální konfigurace aplikace (DB)
 *  Tabulka app_config (key-value), sdílená pro všechny uživatele
 * ===========================================================
 */

/**
 * Načte hodnotu konfigurace z DB (s cache)
 */
function get_app_config(string $key, $default = null) {
    global $pdo;
    static $cache = [];
    if (!isset($cache[$key])) {
        try {
            $stmt = $pdo->prepare("SELECT config_value FROM app_config WHERE config_key = ?");
            $stmt->execute([$key]);
            $row = $stmt->fetchColumn();
            $cache[$key] = $row !== false ? json_decode($row, true) : null;
        } catch (\Throwable $e) {
            $cache[$key] = null;
        }
    }
    return $cache[$key] ?? $default;
}

/**
 * Uloží/aktualizuje hodnotu konfigurace v DB
 */
function set_app_config(string $key, $value): void {
    global $pdo;
    $pdo->prepare(
        "INSERT INTO app_config (config_key, config_value)
         VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
    )->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
}

/**
 * Vloží výchozí hodnoty (INSERT IGNORE — nepřepíše existující)
 *
 * Optimised: static $done prevents repeated calls within the same process;
 * _init_done DB flag prevents unnecessary INSERTs on every cold request.
 */
function init_app_config(): void {
    static $done = false;
    if ($done) return;

    global $pdo;

    // Fast path: DB already initialised in a previous request
    $cached = get_app_config('_init_done', '0');
    if ($cached === '1') {
        $done = true;
        return;
    }

    $defaults = [
        'allowed_themes' => all_themes(),
        'allowed_langs'  => all_langs(),
        'visible_pages'  => all_pages(),
        '_init_done'     => '1',
    ];

    $stmt = $pdo->prepare("INSERT IGNORE INTO app_config (config_key, config_value) VALUES (?, ?)");
    foreach ($defaults as $key => $val) {
        try {
            $encoded = json_encode($val, JSON_UNESCAPED_UNICODE);
            $stmt->execute([$key, $encoded]);
        } catch (\Throwable $e) {
            // Ignore — table may not exist yet on first-ever run before migrations
        }
    }

    $done = true;
}
