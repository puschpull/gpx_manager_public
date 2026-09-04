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
    return ['stats', 'calendar', 'heatmap', 'photo_heatmap', 'map_search', 'nearby', 'photo_nearby', 'filter', 'compare', 'settings', 'virtual_tracks', 'links'];
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
        'links'          => ['links.php',          'compass',     t('nav_links',          'Podobné weby'),    'links'],
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
        'plan_overlay'   => '🗺️ ' . t('ft_plan_overlay', 'Porovnání s plánem (detail trasy)'),
        'baro_note'      => '⛰️ ' . t('ft_baro_note', 'Vysvětlení rozdílu výšky start/cíl (okruhy)'),
        'radar_now'      => '🌧️ ' . t('ft_radar_now', 'Aktuální srážky z radaru ČHMÚ (Plánovač)'),
    ];
}

/**
 * Registr mapových vrstev, které přicházejí ze sdílené továrny
 * (js/lib/map-factory.js) a chovají se stejně na všech mapách.
 *
 * Klíč popisku MUSÍ přesně odpovídat názvu vrstvy v map-factory.js —
 * je to zároveň popisek v ovladači vrstev a identifikátor, pod kterým si
 * prohlížeč pamatuje naposledy zvolený podklad.
 *
 * 'needs' = konfigurační klíč, bez kterého se vrstva nezobrazí tak jako tak.
 */
function map_layer_defs(): array {
    return [
        // --- podkladové mapy ---
        'osm'         => ['label' => '🗺️ OSM',                       'section' => 'base'],
        'topo'        => ['label' => '🏞️ Topo',                      'section' => 'base'],
        'esri'        => ['label' => '🌍 Satelit (Esri)',             'section' => 'base'],
        'mapy_basic'  => ['label' => '🗺️ Mapy.com základní',         'section' => 'base', 'needs' => 'MAPYCOM_API_KEY'],
        'mapy_out'    => ['label' => '🧭 Mapy.com turistická',        'section' => 'base', 'needs' => 'MAPYCOM_API_KEY'],
        'mapy_winter' => ['label' => '❄️ Mapy.com zimní',             'section' => 'base', 'needs' => 'MAPYCOM_API_KEY'],
        'mapy_aerial' => ['label' => '✈️ Mapy.com letecká',           'section' => 'base', 'needs' => 'MAPYCOM_API_KEY'],
        'tf'          => ['label' => '🤾 Thunderforest',              'section' => 'base', 'needs' => 'TF_API_KEY'],
        'cyclosm'     => ['label' => '🌲 CyclOSM (všechny cesty)',    'section' => 'base'],
        'ztm'         => ['label' => '🇨🇿 ZTM ČÚZK (jen Česko)',       'section' => 'base'],

        // --- překryvné vrstvy ---
        'wm_hiking'   => ['label' => '🤾 Turistické značení (Waymarked)', 'section' => 'overlay'],
        'wm_cycling'  => ['label' => '🚴 Cyklotrasy (Waymarked)',         'section' => 'overlay'],
        'wm_mtb'      => ['label' => '🚵 MTB trasy (Waymarked)',          'section' => 'overlay'],
        'hillshade'   => ['label' => '⛰️ Stínování terénu (Esri)',        'section' => 'overlay'],
        'poi'         => ['label' => '📌 Turistické body (OSM)',          'section' => 'overlay'],
        'mapy_names'  => ['label' => '🏷️ Popisky a hranice (Mapy.com)',   'section' => 'overlay', 'needs' => 'MAPYCOM_API_KEY'],
    ];
}

/**
 * Vrstvy, které přidává až konkrétní stránka — v administraci se jen vypisují.
 * Zapínat je tam nedává smysl: existují jen tam, kde mají význam, a část se
 * řídí jinde (klíč k API, volitelné funkce).
 */
function map_context_layers(): array {
    return [
        '🗺️ Moje trasa'                => t('mlayer_ctx_track',     'detail trasy — vlastní GPX linie'),
        '📸 Moje fotografie'           => t('mlayer_ctx_photos',    'detail trasy — vlastní fotky'),
        '📍 Polohy fotek na trase'     => t('mlayer_ctx_photopos',  'detail trasy — body fotek podél trasy'),
        '🖼️ Fotografie (Wikimedia)'    => t('mlayer_ctx_wikimedia', 'detail trasy'),
        '📷 Fotografie (Mapillary)'    => t('mlayer_ctx_mapillary', 'jen s klíčem MAPILLARY_TOKEN'),
        '🌧️ Aktuální srážky (ČHMÚ)'    => t('mlayer_ctx_radar',     'plánovač — Volitelné funkce'),
    ];
}

/** Klíče vrstev v pořadí, jak je zvolil administrátor (chybějící na konec). */
function map_layer_order(string $section): array {
    $all = array_keys(array_filter(map_layer_defs(),
        static fn($d) => $d['section'] === $section));
    $saved = (array)get_app_config('map_layers_order', [])[$section] ?? [];
    $order = array_values(array_intersect($saved, $all));
    foreach ($all as $k) {
        if (!in_array($k, $order, true)) $order[] = $k;
    }
    return $order;
}

/** Je vrstva zapnutá? Neuvedená vrstva je zapnutá (výchozí stav). */
function map_layer_enabled(string $key): bool {
    $off = (array)get_app_config('map_layers_off', []);
    return !in_array($key, $off, true);
}

/**
 * Nabídka velikostí mapy pro admin UI: klíč => popisek.
 * Volný počet pixelů schválně nenabízíme — 900 px na notebooku znamená,
 * že pod mapou nic není, a nikdo to nepozná dřív, než si to nastaví.
 */
function map_height_options(): array {
    return [
        'low'    => t('map_h_low',    'Nízká (400 px)'),
        'mid'    => t('map_h_mid',    'Střední (500 px)'),
        'high'   => t('map_h_high',   'Vysoká (650 px)'),
        'screen' => t('map_h_screen', 'Podle výšky okna'),
    ];
}

/**
 * CSS hodnota výšky mapy: [výška, minimální výška].
 * $full = stránka, kde je mapa hlavní obsah (heatmapy, hledání na mapě).
 */
function map_height_css(bool $full = false): array {
    if ($full) return ['calc(100vh - 200px)', '400px'];

    switch ((string)get_app_config('map_height', 'mid')) {
        case 'low':    return ['400px', '0'];
        case 'high':   return ['650px', '0'];
        case 'screen': return ['calc(100vh - 220px)', '360px'];
        default:       return ['500px', '0'];
    }
}

/**
 * Stránky, kde je mapa hlavní obsah — na nich lze nechat celou výšku okna
 * (zaškrtávátko v Administraci).
 */
function map_first_pages(): array {
    return ['heatmap.php', 'photo_heatmap.php', 'map_search.php'];
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
