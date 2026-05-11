<?php
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
 */
function init_app_config(): void {
    global $pdo;
    $defaults = [
        'allowed_themes' => ['classic','dark','darkblue','darkgreen','blue','green','minimal','lightgray','brown'],
        'allowed_langs'  => ['cs','en','de','sk','es','fr','pl','it'],
        'visible_pages'  => ['stats','calendar','heatmap','map_search','nearby','filter','compare','settings'],
    ];
    foreach ($defaults as $key => $val) {
        try {
            $pdo->prepare("INSERT IGNORE INTO app_config (config_key, config_value) VALUES (?, ?)")
                ->execute([$key, json_encode($val, JSON_UNESCAPED_UNICODE)]);
        } catch (\Throwable $e) {
            // Ignorovat chybu pokud tabulka ještě neexistuje
        }
    }
}
