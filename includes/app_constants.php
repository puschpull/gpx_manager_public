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
    return ['stats', 'calendar', 'heatmap', 'photo_heatmap', 'map_search', 'nearby', 'photo_nearby', 'filter', 'compare', 'settings', 'virtual_tracks'];
}

/**
 * Položky horního menu ve VÝCHOZÍM pořadí (jediné místo definice).
 * key => [href, lucide ikona, label, klíč viditelnosti pro návštěvníky | null]
 * null = položka je v menu vždy; jinak se návštěvníkovi zobrazí jen pokud je
 * stránka ve visible_pages (admin vidí vždy vše).
 */
function nav_menu_items(): array {
    return [
        'stats'          => ['stats.php',          'bar-chart-2', t('nav_stats',          'Statistiky'),      null],
        'calendar'       => ['calendar.php',       'calendar',    t('nav_calendar',       'Kalendář'),        null],
        'nearby'         => ['nearby.php',         'map-pin',     t('h1_nearby',          'Trasy v okolí'),   null],
        'heatmap'        => ['heatmap.php',        'flame',       t('nav_heatmap',        'Heatmapa'),        null],
        'virtual_tracks' => ['virtual_tracks.php', 'route',       t('nav_virtual_tracks', 'Virtuální trasy'), 'virtual_tracks'],
        'photos'         => ['photos.php',         'image',       t('nav_photos',         'Fotografie'),      null],
        'photo_nearby'   => ['photo_nearby.php',   'aperture',    t('nav_photo_nearby',   'Fotky v okolí'),   'photo_nearby'],
        'photo_heatmap'  => ['photo_heatmap.php',  'camera',      t('nav_photo_heatmap',  'Foto-heatmapa'),   'photo_heatmap'],
        // 'planner' není v all_pages() → návštěvník ho nikdy nevidí (jen admin);
        // samotná stránka je navíc za auth.php.
        'planner'        => ['planner.php',        'signpost',    t('nav_planner',        'Plánovač'),        'planner'],
    ];
}

/**
 * Pořadí položek menu: z app_config 'nav_order' (nastavuje admin v Administraci),
 * fallback výchozí pořadí z nav_menu_items(). Neznámé klíče se zahodí,
 * chybějící (nově přidané stránky) se přidají na konec.
 */
function nav_menu_order(): array {
    $keys = array_keys(nav_menu_items());
    $cfg  = get_app_config('nav_order', $keys);
    $out  = is_array($cfg) ? array_values(array_intersect($cfg, $keys)) : [];
    foreach ($keys as $k) {
        if (!in_array($k, $out, true)) $out[] = $k;
    }
    return $out;
}

/**
 * Volitelné funkce aplikace (Administrace → Volitelné funkce).
 * Uloženo v app_config 'feature_flags' jako {klíč: bool}. Když klíč
 * v konfiguraci chybí (nová funkce, čistá instalace), je ZAPNUTO.
 */
function feature_enabled(string $key): bool {
    $cfg = get_app_config('feature_flags', null);
    if (!is_array($cfg) || !array_key_exists($key, $cfg)) {
        return true;
    }
    return (bool)$cfg[$key];
}

/**
 * Seznam volitelných funkcí pro admin UI: klíč => popisek (t()).
 */
function feature_flag_labels(): array {
    return [
        'replay'         => '▶️ ' . t('ft_replay', 'Přehrávač výšlapu (detail trasy)'),
        'replay_weather' => '🌦️ ' . t('ft_replay_weather', 'Počasí u turisty (při přehrávání)'),
        'replay_radar'   => '🌧️ ' . t('ft_replay_radar', 'Srážkové pole (animace deště)'),
        'replay_photos'  => '📷 ' . t('ft_replay_photos', 'Míjené fotky (při přehrávání)'),
    ];
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
