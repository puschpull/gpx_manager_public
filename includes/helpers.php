<?php
/**
 * ===========================================================
 *  helpers.php
 *  Společné pomocné funkce pro gpx_manage
 * ===========================================================
 */

/* ============================================================
 *  Uploads path / URL — konfigurovatelné přes app_config
 *  Umožňuje sdílet adresář uploads/ mezi více instancemi (např.
 *  čtení produkčních dat z testovací subdomény).
 *  ============================================================ */

/**
 * Vrátí absolutní filesystem path k uploads/ (s lomítkem na konci)
 * nebo k souboru/podadresáři uvnitř uploads.
 * Default: __DIR__/../uploads/ (relativně k aplikaci)
 */
function uploads_fs(string $relative = ''): string {
    static $base = null;
    if ($base === null) {
        $cfg = function_exists('get_app_config') ? trim((string)get_app_config('uploads_fs_path', '')) : '';
        if ($cfg !== '') {
            $base = rtrim($cfg, "/\\") . '/';
        } else {
            $base = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
        }
    }
    return $base . ltrim($relative, "/\\");
}

/**
 * Vrátí URL k uploads/ pro použití v <img src=...>, <a href=...> apod.
 * Default: 'uploads/' (relativně k aktuální stránce).
 * Konfigurace: lze nastavit absolutní URL (např. https://yourdomain.com/gpx/uploads/).
 */
function uploads_url(string $relative = ''): string {
    static $base = null;
    if ($base === null) {
        $cfg = function_exists('get_app_config') ? trim((string)get_app_config('uploads_url', '')) : '';
        $base = $cfg !== '' ? rtrim($cfg, '/') . '/' : 'uploads/';
    }
    return $base . ltrim($relative, '/');
}

/* Zkratky pro fotky — používané v photo_helper.php a šablonách */
function photo_thumb_url(string $filename): string { return uploads_url('photos/thumbs/' . rawurlencode($filename)); }
function photo_full_url(string $filename): string  { return uploads_url('photos/' . rawurlencode($filename)); }
function gpx_url(string $filename): string         { return uploads_url(rawurlencode($filename)); }
function thumb_url(string $filename): string       { return uploads_url('thumbs/' . rawurlencode($filename)); }

/**
 * Bezpečné escapování textu pro HTML výstup
 */
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ===========================================================
 *  i18n — příprava pro vícejazyčnost
 * =========================================================== */

/**
 * Vrátí aktuální jazyk aplikace (z cookie, výchozí 'cs')
 */
function app_lang(): string {
    static $lang = null;
    if ($lang === null) {
        // Priorita: ?app_lang=XX (URL) > cookie > default 'cs'
        // URL parameter zároveň perzistuje cookie (s expirací rok)
        if (!empty($_GET['app_lang'])) {
            $lang = (string)$_GET['app_lang'];
            if (!headers_sent()) {
                setcookie('app_lang', $lang, time() + 365 * 24 * 3600, '/');
            }
            $_COOKIE['app_lang'] = $lang;
        } else {
            $lang = $_COOKIE['app_lang'] ?? 'cs';
        }
        // Respektovat povolené jazyky z globální konfigurace
        $allowed = ['cs','en','de','sk','es','fr','pl','it'];
        if (function_exists('get_app_config')) {
            $cfg = get_app_config('allowed_langs', $allowed);
            if (!empty($cfg)) $allowed = $cfg;
        }
        if (!in_array($lang, $allowed)) $lang = $allowed[0] ?? 'cs';
    }
    return $lang;
}

/**
 * Vykreslí přepínač jazyka (vlajka s průhledným selectem přes ni)
 * @param array $allowedLangs  seznam povolených jazyků
 */
function renderLangSelector(array $allowedLangs): void {
    $langToFlag = ['cs'=>'cz','en'=>'gb','de'=>'de','sk'=>'sk','es'=>'es','fr'=>'fr','pl'=>'pl','it'=>'it'];
    $langNames  = ['cs'=>'Čeština','en'=>'English','de'=>'Deutsch','sk'=>'Slovenčina',
                   'es'=>'Español','fr'=>'Français','pl'=>'Polski','it'=>'Italiano'];
    $cur     = app_lang();
    $flagCode = $langToFlag[$cur] ?? 'cz';
    $flagSrc  = 'lang/flags/' . $flagCode . '.png';
    $altText  = htmlspecialchars($langNames[$cur] ?? $cur, ENT_QUOTES, 'UTF-8');
    echo '<span class="lang-picker" title="Jazyk / Language">';
    echo '<img id="lang-flag" src="' . $flagSrc . '" alt="' . $altText . '" class="lang-flag-img">';
    echo '<select id="lang-selector" class="lang-selector-hidden">';
    foreach ($allowedLangs as $lc) {
        $sel  = $lc === $cur ? ' selected' : '';
        $name = htmlspecialchars($langNames[$lc] ?? $lc, ENT_QUOTES, 'UTF-8');
        echo "<option value=\"$lc\"$sel>$name</option>";
    }
    echo '</select>';
    echo '</span>';
}

/**
 * Vykreslí celý blok header-meta (theme selector, lang picker, DB/server info)
 * @param array  $available_themes  seznam dostupných témat
 * @param string $theme             aktivní téma
 * @param array  $allowedLangs      seznam povolených jazyků
 */
function renderHeaderMeta(array $available_themes, string $theme, array $allowedLangs): void {
    $themeLabels = [
        'classic'   => 'Classic',   'dark'      => 'Dark',
        'darkblue'  => 'Dark Blue', 'darkgreen' => 'Dark Green',
        'blue'      => 'Blue',      'green'     => 'Green',
        'minimal'   => 'Minimal',   'lightgray' => 'Light Gray',
        'brown'     => 'Brown',
    ];
    echo '<span class="header-meta">';
    echo '<span class="header-controls">';
    echo '<select id="theme-selector" class="select">';
    foreach ($available_themes as $th) {
        $sel   = $th === $theme ? ' selected' : '';
        $label = htmlspecialchars($themeLabels[$th] ?? ucfirst($th), ENT_QUOTES, 'UTF-8');
        echo "<option value=\"$th\"$sel>$label</option>";
    }
    echo '</select>';
    renderLangSelector($allowedLangs);
    echo '</span>';
    echo '<small class="header-info">';
    echo t('db_label') . ': <b>' . DB_NAME . '</b> | ' . t('server_label') . ': <b>' . APP_ENV . '</b>';
    echo '</small>';
    echo '</span>';
}

/**
 * Přeloží klíč do aktuálního jazyka
 * Pokud klíč neexistuje, vrátí klíč samotný
 */
function t(string $key): string|array {
    static $strings = null;
    if ($strings === null) {
        $langFile = __DIR__ . '/../lang/' . app_lang() . '.php';
        $strings = file_exists($langFile) ? require $langFile : [];
    }
    return $strings[$key] ?? $key;
}

/* ===========================================================
 *  Jednotky — metrické / imperiální
 * =========================================================== */

/**
 * Vrátí aktuální systém jednotek ('metric' nebo 'imperial')
 */
function app_units(): string {
    static $units = null;
    if ($units === null) {
        $units = $_COOKIE['app_units'] ?? 'metric';
        if (!in_array($units, ['metric', 'imperial'])) $units = 'metric';
    }
    return $units;
}

/**
 * Formátuje vzdálenost (km → mi při imperial)
 */
function fmtDist(?float $km, int $decimals = 1): string {
    if ($km === null) return '—';
    if (app_units() === 'imperial') {
        return number_format($km * 0.621371, $decimals, ',', ' ') . ' mi';
    }
    return number_format($km, $decimals, ',', ' ') . ' km';
}

/**
 * Formátuje výšku/převýšení (m → ft při imperial)
 */
function fmtElev(?float $m, int $decimals = 0): string {
    if ($m === null) return '—';
    if (app_units() === 'imperial') {
        return number_format($m * 3.28084, $decimals, ',', ' ') . ' ft';
    }
    return number_format($m, $decimals, ',', ' ') . ' m';
}

/**
 * Formátuje rychlost (km/h → mph při imperial)
 */
function fmtSpeed(?float $kmh, int $decimals = 1): string {
    if ($kmh === null) return '—';
    if (app_units() === 'imperial') {
        return number_format($kmh * 0.621371, $decimals, ',', ' ') . ' mph';
    }
    return number_format($kmh, $decimals, ',', ' ') . ' km/h';
}

/**
 * Formátuje počet sekund do formátu H:MM:SS
 */
function formatSecondsToHMS(?int $seconds): string {
    if ($seconds === null) {
        return '';
    }
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return sprintf('%d:%02d:%02d', $h, $m, $s);
}

/**
 * Vytvoří query string s přenesením aktivních filtrů
 */
function build_query(array $override = []): string {
    $keys = [
		'q','color','cat','cat_mode','fav_only','diff_min','diff_max','activity',
        'dist_min','dist_max','dist_inv',
        'ascent_min','ascent_max','ascent_inv',
        'descent_min','descent_max','descent_inv',
        'savg_min','savg_max','savg_inv',
        'savg_total_min','savg_total_max','savg_total_inv',
        'smax_min','smax_max','smax_inv',
        'elevation_min_val','elevation_max_val','elevation_inv',
        'date_from','date_to','date_inv',
        'sort_by','sort_dir',
        'per_page','page','filter_submit'
    ];

    $data = [];
    foreach ($keys as $k) {
        if (isset($_GET[$k])) $data[$k] = $_GET[$k];
    }

    foreach ($override as $k => $v) {
        if ($v === null) unset($data[$k]);
        else $data[$k] = $v;
    }

    return http_build_query($data);
}

/**
 * Klikatelný sloupec pro řazení tabulky
 */
function sort_th(string $label, string $col, string $currentBy, string $currentDir): string {
    $isCurrent = ($currentBy === $col);
    $nextDir   = ($isCurrent && $currentDir === 'ASC') ? 'DESC' : 'ASC';
    $qs = build_query(['sort_by' => $col, 'sort_dir' => $nextDir, 'page' => 1]);
    $arrow = $isCurrent ? ($currentDir === 'ASC' ? ' ↑' : ' ↓') : '';
    return '<a href="?' . h($qs) . '" title="Seřadit">' . $label . $arrow . '</a>';
}

/**
 * Výpočet obtížnosti trasy (1–5)
 * Založen na: vzdálenost, stoupání, průměrný sklon
 *
 * 1 = Velmi snadná (procházka)
 * 2 = Snadná
 * 3 = Středně náročná
 * 4 = Náročná
 * 5 = Velmi náročná
 */
function calculateDifficulty(?float $distance_km, ?float $ascent, ?float $elevation_max, ?float $elevation_min): ?int {
    if ($distance_km === null || $distance_km <= 0) return null;
    if ($ascent === null) $ascent = 0;

    // Body za vzdálenost (0–40)
    $distPoints = min(40, $distance_km * 1.5);

    // Body za stoupání (0–40)
    $ascentPoints = min(40, $ascent * 0.03);

    // Body za průměrný sklon (0–20)
    $slopePoints = 0;
    if ($distance_km > 0 && $ascent > 0) {
        $avgSlope = ($ascent / ($distance_km * 1000)) * 100; // v procentech
        $slopePoints = min(20, $avgSlope * 2);
    }

    $total = $distPoints + $ascentPoints + $slopePoints;

    if ($total <= 15) return 1;
    if ($total <= 30) return 2;
    if ($total <= 50) return 3;
    if ($total <= 70) return 4;
    return 5;
}

/**
 * Textový popis obtížnosti
 */
function difficultyLabel(?int $diff): string {
    return match($diff) {
        1 => 'Velmi snadná',
        2 => 'Snadná',
        3 => 'Středně náročná',
        4 => 'Náročná',
        5 => 'Velmi náročná',
        default => '—',
    };
}

/**
 * Vizuální indikátor obtížnosti (barevné puntíky)
 */
function difficultyBadge(?int $diff): string {
    if ($diff === null) return '<span class="diff-badge diff-0">—</span>';
    $colors = [1 => '#4caf50', 2 => '#8bc34a', 3 => '#ff9800', 4 => '#f44336', 5 => '#9c27b0'];
    $label = difficultyLabel($diff);
    $dots = str_repeat('●', $diff) . str_repeat('○', 5 - $diff);
    $color = $colors[$diff] ?? '#999';
    return "<span class=\"diff-badge diff-{$diff}\" title=\"{$label}\" style=\"color:{$color};\">{$dots}</span>";
}

/**
 * Automatické rozpoznání typu aktivity podle průměrné rychlosti a profilu
 *
 * Vrací řetězec s názvem kategorie aktivity:
 * - "Pěšky"     — avg speed < 6 km/h
 * - "Běh"       — avg speed 6–12 km/h, stoupání > 50m
 * - "Kolo"      — avg speed 12–35 km/h
 * - "E-bike"    — avg speed 15–30 km/h, hodně stoupání
 * - "Auto"      — avg speed > 35 km/h
 * - "Lyže"      — nelze spolehlivě detekovat, null
 * - null         — nelze určit
 */
function detectActivityType(?float $speed_avg, ?float $speed_max, ?float $distance_km, ?float $ascent): ?string {
    if ($speed_avg === null || $speed_avg <= 0) return null;
    if ($distance_km === null || $distance_km <= 0) return null;

    // Pokud je max rychlost extrémně vysoká → auto
    if ($speed_max !== null && $speed_max > 80) return 'Auto';

    // Průměrná rychlost rozhoduje
    if ($speed_avg > 35) return 'Auto';

    if ($speed_avg >= 12) {
        // Kolo vs e-bike — e-bike má často vyšší stoupání při vyšší rychlosti
        // Zjednodušeně: pokud je poměr stoupání/km vysoký a rychlost 15+, je to e-bike
        if ($ascent !== null && $distance_km > 0) {
            $ascentPerKm = $ascent / $distance_km;
            if ($speed_avg >= 15 && $ascentPerKm > 20) return 'E-bike';
        }
        return 'Kolo';
    }

    if ($speed_avg >= 6) {
        // Běh vs rychlá chůze
        if ($ascent !== null && $ascent > 200) return 'Turistika';
        return 'Běh';
    }

    // Pomalá chůze
    if ($ascent !== null && $ascent > 100) return 'Turistika';
    return 'Pěšky';
}

/**
 * Vizuální badge pro typ aktivity
 */
function activityBadge(?string $type): string {
    if ($type === null || $type === '') return '<span class="activity-badge activity-none">—</span>';
    $icons = [
        'Pěšky'     => '🚶',
        'Turistika'  => '🥾',
        'Běh'        => '🏃',
        'Kolo'       => '🚴',
        'E-bike'     => '⚡🚴',
        'Auto'       => '🚗',
    ];
    $icon = $icons[$type] ?? '❓';
    return "<span class=\"activity-badge\" title=\"" . h($type) . "\">{$icon} " . h($type) . "</span>";
}

/**
 * Sestaví SQL podmínku pro rozsahový filtr (s podporou inverze)
 *
 * Normální:  col >= min AND col <= max
 * Inverzní:  col < min OR col > max
 */
function buildRangeSQL(string $col, ?string $min, ?string $max, bool $inv, array &$params, string $minKey, string $maxKey): ?string
{
    $hasMin = ($min !== null && $min !== '');
    $hasMax = ($max !== null && $max !== '');

    if (!$hasMin && !$hasMax) return null;

    if (!$inv) {
        // Normální rozsah
        $parts = [];
        if ($hasMin) { $parts[] = "$col >= :$minKey"; $params[":$minKey"] = (float)$min; }
        if ($hasMax) { $parts[] = "$col <= :$maxKey"; $params[":$maxKey"] = (float)$max; }
        return implode(' AND ', $parts);
    } else {
        // Inverzní rozsah
        $parts = [];
        if ($hasMin) { $parts[] = "$col < :$minKey";  $params[":$minKey"] = (float)$min; }
        if ($hasMax) { $parts[] = "$col > :$maxKey";  $params[":$maxKey"] = (float)$max; }
        if (count($parts) === 2) return '(' . implode(' OR ', $parts) . ')';
        return $parts[0] ?? null;
    }
}

/**
 * Dynamické sestavení WHERE podmínky podle aktivních filtrů
 */
function buildFilterSQL(bool $filterActive, array &$params, string $prefix = ''): string {
    if (!$filterActive) return '';

    $clauses = [];

// --- Textové hledání ---
$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $clauses[] = "({$prefix}track_name LIKE :q 
        OR {$prefix}alt_title LIKE :q 
        OR {$prefix}note LIKE :q 
        OR {$prefix}filename LIKE :q 
        OR {$prefix}device LIKE :q
        OR EXISTS (
            SELECT 1 FROM track_categories tc
            JOIN categories c ON c.id = tc.category_id
            WHERE tc.track_id = {$prefix}id AND c.name LIKE :q
        ))";
    $params[':q'] = "%$q%";
}

    // --- Oblíbené ---
    $fav = trim($_GET['fav_only'] ?? '');
    if ($fav === '1') {
        $clauses[] = "{$prefix}is_favorite = 1";
    }

    // --- Obtížnost ---
    $diff_min = trim($_GET['diff_min'] ?? '');
    $diff_max = trim($_GET['diff_max'] ?? '');
    if ($diff_min !== '') {
        $clauses[] = "{$prefix}difficulty >= :diff_min";
        $params[':diff_min'] = (int)$diff_min;
    }
    if ($diff_max !== '') {
        $clauses[] = "{$prefix}difficulty <= :diff_max";
        $params[':diff_max'] = (int)$diff_max;
    }

    // --- Aktivita ---
    $activity = trim($_GET['activity'] ?? '');
    if ($activity !== '') {
        $clauses[] = "{$prefix}activity_type = :activity";
        $params[':activity'] = $activity;
    }

    // --- Barva ---
    $color = trim($_GET['color'] ?? '');
    if ($color !== '') {
        $clauses[] = "{$prefix}color = :color";
        $params[':color'] = $color;
    }

// --- Kategorie ---
$cat = $_GET['cat'] ?? [];
if (!is_array($cat)) $cat = [$cat];
$cat = array_values(array_filter(array_map('trim', $cat)));
if (!empty($cat)) {
    $catMode = ($_GET['cat_mode'] ?? 'or') === 'and' ? 'and' : 'or';

    if ($catMode === 'or') {
        // OR — trasy které mají alespoň jednu ze zvolených kategorií
        $placeholders = [];
        foreach ($cat as $i => $_) $placeholders[] = ":cat{$i}";
        $in = implode(',', $placeholders);
        $clauses[] = "EXISTS (
            SELECT 1 FROM track_categories tc
            JOIN categories c ON c.id = tc.category_id
            WHERE tc.track_id = {$prefix}id AND c.name IN ($in)
        )";
        foreach ($cat as $i => $v) $params[":cat{$i}"] = $v;
    } else {
        // AND — trasy které mají všechny zvolené kategorie
        $andClauses = [];
        foreach ($cat as $i => $v) {
            $andClauses[] = "EXISTS (
                SELECT 1 FROM track_categories tc
                JOIN categories c ON c.id = tc.category_id
                WHERE tc.track_id = {$prefix}id AND c.name = :cat{$i}
            )";
            $params[":cat{$i}"] = $v;
        }
        $clauses[] = implode(' AND ', $andClauses);
    }
}

    // --- Datum ---
    $date_from = trim($_GET['date_from'] ?? '');
    $date_to   = trim($_GET['date_to']   ?? '');
    $date_inv  = !empty($_GET['date_inv']);
    if ($date_from !== '' || $date_to !== '') {
        // Porovnáváme DATE(date_start) aby fungovalo kliknutí na den v kalendáři
        // (date_end může být datetime 2024-07-15 12:30:00 > '2024-07-15 00:00:00' → podmínka by selhala)
        if (!$date_inv) {
            if ($date_from !== '') { $clauses[] = "DATE({$prefix}date_start) >= :date_from"; $params[':date_from'] = $date_from; }
            if ($date_to   !== '') { $clauses[] = "DATE({$prefix}date_start) <= :date_to";   $params[':date_to']   = $date_to; }
        } else {
            $parts = [];
            if ($date_from !== '') { $parts[] = "DATE({$prefix}date_start) < :date_from"; $params[':date_from'] = $date_from; }
            if ($date_to   !== '') { $parts[] = "DATE({$prefix}date_start) > :date_to";   $params[':date_to']   = $date_to; }
            if (count($parts) === 2) $clauses[] = '(' . implode(' OR ', $parts) . ')';
            elseif ($parts)         $clauses[] = $parts[0];
        }
    }

    // --- Rozsahové filtry s inverzí ---
    $ranges = [
        ['col' => "{$prefix}distance_km",    'min' => 'dist_min',       'max' => 'dist_max',       'inv' => 'dist_inv'],
        ['col' => "{$prefix}ascent",         'min' => 'ascent_min',     'max' => 'ascent_max',     'inv' => 'ascent_inv'],
        ['col' => "{$prefix}descent",        'min' => 'descent_min',    'max' => 'descent_max',    'inv' => 'descent_inv'],
        ['col' => "{$prefix}speed_avg",      'min' => 'savg_min',       'max' => 'savg_max',       'inv' => 'savg_inv'],
        ['col' => "{$prefix}speed_avg_total",'min' => 'savg_total_min', 'max' => 'savg_total_max', 'inv' => 'savg_total_inv'],
        ['col' => "{$prefix}speed_max",      'min' => 'smax_min',       'max' => 'smax_max',       'inv' => 'smax_inv'],
        ['col' => "{$prefix}elevation_min",  'min' => 'elevation_min_val', 'max' => 'elevation_min_max', 'inv' => 'elevation_min_inv'],
        ['col' => "{$prefix}elevation_max",  'min' => 'elevation_max_val', 'max' => 'elevation_max_max', 'inv' => 'elevation_max_inv'],
    ];

    // Deduplikace
    $seen = [];
    foreach ($ranges as $r) {
        $key = $r['min'] . '_' . $r['max'];
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $min = trim($_GET[$r['min']] ?? '');
        $max = trim($_GET[$r['max']] ?? '');
        $inv = !empty($_GET[$r['inv']]);

        $sql = buildRangeSQL($r['col'], $min ?: null, $max ?: null, $inv, $params, $r['min'], $r['max']);
        if ($sql !== null) $clauses[] = $sql;
    }

    return implode(' AND ', $clauses);
}