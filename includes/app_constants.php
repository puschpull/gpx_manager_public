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
 * Kompletní seznam všech témat, která aplikace podporuje.
 * Toto je jediné místo, kde se tento seznam definuje.
 */
function all_themes(): array {
    return ['classic', 'dark', 'darkblue', 'darkgreen', 'blue', 'green', 'minimal', 'lightgray', 'brown'];
}

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
    return ['stats', 'calendar', 'heatmap', 'photo_heatmap', 'map_search', 'nearby', 'filter', 'compare', 'settings'];
}

/**
 * Vrátí seznam témat povolených administrátorem (průnik DB konfigurace a all_themes()).
 * Pokud DB neobsahuje platnou hodnotu, vrátí all_themes().
 */
function available_themes(): array {
    $allowed = get_app_config('allowed_themes', json_encode(all_themes()));
    $arr = is_string($allowed) ? json_decode($allowed, true) : $allowed;
    return is_array($arr) && count($arr) > 0
        ? array_values(array_intersect($arr, all_themes()))
        : all_themes();
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

/**
 * Vrátí aktuálně aktivní téma pro tento request.
 * Priorita: GET ?theme= → cookie theme → první dostupné téma → 'classic'.
 * Vždy validuje proti available_themes() — žádný user-supplied string nepronikne.
 */
function active_theme(): string {
    $available = available_themes();
    if (!empty($_GET['theme']) && in_array($_GET['theme'], $available, true)) {
        return $_GET['theme'];
    }
    if (!empty($_COOKIE['theme']) && in_array($_COOKIE['theme'], $available, true)) {
        return $_COOKIE['theme'];
    }
    return $available[0] ?? 'classic';
}

/**
 * Pokud je v GET předán platný ?theme=, zapíše cookie (1 rok) a aktualizuje $_COOKIE.
 * Musí být voláno před jakýmkoliv výstupem (headers_sent() guard).
 */
function init_theme_cookie(): void {
    if (headers_sent()) {
        return;
    }
    if (!empty($_GET['theme']) && in_array($_GET['theme'], available_themes(), true)) {
        setcookie('theme', $_GET['theme'], time() + 365 * 24 * 3600, '/');
        $_COOKIE['theme'] = $_GET['theme'];
    }
}
