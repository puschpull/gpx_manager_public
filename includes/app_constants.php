<?php
declare(strict_types=1);

/**
 * ===========================================================
 *  app_constants.php — Centrální konstanty aplikace
 *  Definuje master listy témat, jazyků a stránek.
 *  Nahrazuje ~15 duplicitních polí rozsetých po souborech.
 * ===========================================================
 */

/**
 * Kompletní seznam všech jazyků, které aplikace podporuje.
 * Toto je jediné místo, kde se tento seznam definuje.
 */
function all_langs(): array {
    return ['cs', 'en', 'de', 'sk', 'es', 'fr', 'pl', 'it'];
}

/**
 * Kompletní seznam všech stránek, které lze zpřístupnit návštěvníkům.
 * Toto je jediné místo, kde se tento seznam definuje.
 */
function all_pages(): array {
    return ['stats', 'calendar', 'heatmap', 'photo_heatmap', 'map_search', 'nearby', 'filter', 'compare', 'settings', 'virtual_tracks'];
}

/**
 * Vrátí seznam jazyků povolených administrátorem (průnik DB konfigurace a all_langs()).
 * Pokud DB neobsahuje platnou hodnotu, vrátí all_langs().
 */
function available_langs(): array {
    $allowed = get_app_config('allowed_langs', json_encode(all_langs()));
    $arr = is_string($allowed) ? json_decode($allowed, true) : $allowed;
    return is_array($arr) && count($arr) > 0
        ? array_values(array_intersect($arr, all_langs()))
        : all_langs();
}
