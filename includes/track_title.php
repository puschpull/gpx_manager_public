<?php
declare(strict_types=1);

/**
 * ===========================================================
 *  track_title.php — čitelný titulek trasy
 *
 *  Garmin ukládá do GPX jako název trasy okamžik, kdy záznam
 *  uložil („2026-08-15 14:40:14“), filtrace tras zase nechává
 *  „Cleaned Track“. Takový název je k ničemu
 *  v panelu prohlížeče, v záložkách i v náhledu odkazu, který
 *  se vloží do diskuse — ze všech údajů o výšlapu je čas
 *  dokončení ten nejméně zajímavý.
 *
 *  Tenhle modul z takového razítka poskládá titulek z toho, co
 *  o trase víme: název místa (reverzní geokódování STARTU, a u
 *  přejezdové trasy i cíle, přes Mapy.com), typ aktivity a datum.
 *  Data v aplikaci nemění — název trasy v seznamu i v nadpisu
 *  zůstává, jaký je.
 *
 *  Zjištěné místo se ukládá do tracks.place_name, takže se na
 *  API sáhne jednou za život trasy.
 * ===========================================================
 */
require_once __DIR__ . '/gpx_parser.php';   // haversine()

/**
 * Je název trasy jen časové razítko z přístroje?
 * Schválně se porovnává CELÝ název — „2026-08-15 Vidim okruh“
 * je smysluplný název a sahat se na něj nesmí.
 */
function track_name_is_timestamp(?string $name): bool {
    $name = trim((string)$name);
    if ($name === '') return false;
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $name);
}

/**
 * Neříká název vůbec nic? Kromě časového razítka sem patří i názvy, které
 * do GPX zapsal nástroj nebo přístroj: „Cleaned Track“ nechává filtrace tras
 * (v tomhle archivu je jich 385), „ACTIVE LOG“ a „Track 001“ zase Garmin.
 *
 * Seznam je krátký a doslovný právě proto, aby nesebral skutečný název —
 * „Výlet Zuberec“ nebo „Track přes Ještěd“ musí zůstat.
 */
function track_name_is_generic(?string $name): bool {
    $name = trim((string)$name);
    if ($name === '') return true;
    if (track_name_is_timestamp($name)) return true;

    $bezDiakritiky = mb_strtolower($name);
    $presne = ['cleaned track', 'track', 'trasa', 'untitled', 'unnamed',
               'no name', 'bez nazvu', 'bez názvu', 'new track', 'current track'];
    if (in_array($bezDiakritiky, $presne, true)) return true;

    // „Track 001“, „ACTIVE LOG 15“, „Trasa 3“ — jméno nástroje plus pořadové číslo
    return (bool)preg_match('/^(track|trasa|active log|log)[ _-]?\d+$/iu', $name);
}

/**
 * Reverzní geokódování jednoho bodu přes Mapy.com.
 * Vrací název místa, nebo null (chybí klíč, výpadek, nic nenalezeno).
 *
 * Preferuje se část obce před obcí: u výšlapu je „Dolní Falknov“
 * srozumitelnější orientační bod než „Kytlice“, pod kterou spadá.
 */
function track_place_lookup(float $lat, float $lon): ?string {
    if (!defined('MAPYCOM_API_KEY') || MAPYCOM_API_KEY === '') return null;

    $url = 'https://api.mapy.com/v1/rgeocode?lat=' . rawurlencode((string)round($lat, 6))
         . '&lon=' . rawurlencode((string)round($lon, 6))
         . '&apikey=' . rawurlencode(MAPYCOM_API_KEY);

    $ctx = stream_context_create(['http' => [
        'timeout'       => 4,          // stránka nesmí čekat na cizí server
        'user_agent'    => 'GPXManager/1.0',
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;

    $data = json_decode($raw, true);
    $item = $data['items'][0] ?? null;
    if (!is_array($item)) return null;

    $byType = [];
    foreach (($item['regionalStructure'] ?? []) as $part) {
        if (isset($part['type'], $part['name'])) $byType[$part['type']] = $part['name'];
    }
    $place = $byType['regional.municipality_part']
          ?? $byType['regional.municipality']
          ?? ($item['name'] ?? null);

    $place = is_string($place) ? trim($place) : '';
    return $place !== '' ? mb_substr($place, 0, 120) : null;
}

/**
 * První a poslední bod trasy přímo z GPX, bez plného parsování.
 *
 * Začátek se najde streamovaně, konec z posledního kusu souboru —
 * u trasy s tisíci body se tak nemusí načítat celá do paměti.
 * Vrací [[lat, lon] start, [lat, lon] cíl] nebo null.
 */
function track_endpoints(string $gpxPath): ?array {
    if (!is_file($gpxPath)) return null;
    $re = '/<trkpt\b[^>]*?\blat="(-?[\d.]+)"[^>]*?\blon="(-?[\d.]+)"|'
        . '<trkpt\b[^>]*?\blon="(-?[\d.]+)"[^>]*?\blat="(-?[\d.]+)"/i';

    $pick = static function (array $m): array {
        // Druhá varianta regulárního výrazu má pořadí atributů obráceně
        return $m[1] !== '' ? [(float)$m[1], (float)$m[2]] : [(float)$m[4], (float)$m[3]];
    };

    $h = @fopen($gpxPath, 'rb');
    if (!$h) return null;

    $first = null;
    $buf   = '';
    while (!feof($h) && $first === null) {
        $buf .= fread($h, 16384);
        if (preg_match($re, $buf, $m)) $first = $pick($m);
        // Kdyby soubor začínal dlouhou hlavičkou, ať buffer neroste donekonečna
        if (strlen($buf) > 262144) $buf = substr($buf, -4096);
    }
    if ($first === null) { fclose($h); return null; }

    $size = filesize($gpxPath) ?: 0;
    $tailLen = min($size, 262144);
    fseek($h, max(0, $size - $tailLen));
    $tail = (string)fread($h, $tailLen);
    fclose($h);

    $last = $first;
    if (preg_match_all($re, $tail, $all, PREG_SET_ORDER)) {
        $last = $pick($all[count($all) - 1]);
    }
    return [$first, $last];
}

/**
 * Název místa pro trasu — z databáze, jinak jednorázově z API.
 *
 * Rozhoduje START (a u přejezdové trasy i cíl), ne těžiště: okruh lesem
 * má těžiště uprostřed lesa a nejbližší vesnice je pak náhodná — výšlap
 * ale pojmenovává místo, odkud se vyráželo. Těžiště zůstává jen jako
 * záloha, když se GPX nepodaří přečíst.
 *
 * Prázdný řetězec v place_name znamená „zkoušelo se a nepovedlo“;
 * pak se už API neobtěžuje. Když klíč chybí úplně, nezapisuje se nic,
 * aby se to po jeho doplnění zkusilo znovu.
 */
function track_place_name(\PDO $pdo, array $track): ?string {
    if (array_key_exists('place_name', $track) && $track['place_name'] !== null) {
        $cached = trim((string)$track['place_name']);
        return $cached !== '' ? $cached : null;
    }
    if (!defined('MAPYCOM_API_KEY') || MAPYCOM_API_KEY === '') return null;

    $place = null;
    $ends  = !empty($track['filename']) ? track_endpoints(uploads_fs((string)$track['filename'])) : null;

    if ($ends !== null) {
        [$start, $finish] = $ends;
        $place = track_place_lookup($start[0], $start[1]);

        // Okruh (cíl do 500 m od startu) se pojmenuje jedním místem,
        // přejezd dvěma: „Vidim → Dolní Falknov“.
        $apart = haversine($start[0], $start[1], $finish[0], $finish[1]);
        if ($place !== null && $apart > 500) {
            $end = track_place_lookup($finish[0], $finish[1]);
            if ($end !== null && $end !== $place) {
                $place = mb_substr($place . ' → ' . $end, 0, 120);
            }
        }
    }

    if ($place === null) {
        $lat = $track['centroid_lat'] ?? null;
        $lon = $track['centroid_lon'] ?? null;
        if ($lat === null || $lon === null) return null;
        $place = track_place_lookup((float)$lat, (float)$lon);
    }

    try {
        $pdo->prepare('UPDATE tracks SET place_name = ? WHERE id = ?')
            ->execute([$place ?? '', (int)$track['id']]);
    } catch (\Throwable $e) {
        error_log('track_place_name: ' . $e->getMessage());
    }
    return $place;
}

/** Podstatné jméno podle typu aktivity — výšlap / vyjížďka / výlet. */
function track_activity_noun(?string $activity): string {
    switch ((string)$activity) {
        case 'Kolo':
        case 'E-bike':
            return t('title_noun_ride', 'vyjížďka');
        case 'Auto':
            return t('title_noun_drive', 'výlet');
        default:
            return t('title_noun_hike', 'výšlap');
    }
}

/** Velké písmeno na začátku (funguje i na diakritice). */
function track_ucfirst(string $s): string {
    if ($s === '') return $s;
    return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
}

/**
 * Titulek trasy pro panel prohlížeče, záložky a náhled sdíleného odkazu.
 *
 * Pořadí:
 *   1. alternativní titulek z editace — má přednost před vším,
 *   2. název trasy, pokud to není jen časové razítko z přístroje,
 *   3. složený titulek: „Dolní Falknov — výšlap 26. 7. 2026“,
 *      bez známého místa „Výšlap 26. 7. 2026 od 9:42“.
 */
function track_display_title(\PDO $pdo, array $track): string {
    $alt = trim((string)($track['alt_title'] ?? ''));
    if ($alt !== '') return $alt;

    $name = trim((string)($track['track_name'] ?? ''));
    if (!track_name_is_generic($name)) return $name;

    $ts = !empty($track['date_start']) ? strtotime((string)$track['date_start']) : false;
    if ($ts === false && $name !== '') $ts = strtotime($name);
    if ($ts === false) return $name !== '' ? $name : (string)($track['filename'] ?? '');

    $noun  = track_activity_noun($track['activity_type'] ?? null);
    $date  = date('j. n. Y', $ts);
    $place = track_place_name($pdo, $track);

    if ($place !== null) {
        return $place . ' — ' . $noun . ' ' . $date;
    }
    // Bez místa aspoň čas odchodu — ten řekne víc než čas uložení záznamu
    return track_ucfirst($noun) . ' ' . $date . ' ' . t('title_from_time', 'od') . ' ' . date('G:i', $ts);
}
