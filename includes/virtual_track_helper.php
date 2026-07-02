<?php
declare(strict_types=1);

/**
 * virtual_track_helper.php — shlukování nepřiřazených fotek do virtuálních tras.
 *
 * Virtuální trasa = trasa tvořená body z GPS fotek (ne GPX). Fotky se shluknou
 * do „výletů" podle času i polohy; z každého shluku vznikne virtuální trasa.
 *
 * Znovupoužívá haversine() a GPX_ELEVATION_NOISE_M.
 */

require_once __DIR__ . '/gpx_parser.php';      // haversine()
require_once __DIR__ . '/constants.php';       // GPX_ELEVATION_NOISE_M
require_once __DIR__ . '/generate_thumb.php';  // generate_thumb()

/** Adresář s náhledy virtuálních tras. */
function vt_thumb_dir(): string {
    return uploads_fs('vt_thumbs/');
}

/** Cesta k PNG náhledu virtuální trasy. */
function vt_thumb_path(int $vtId): string {
    return vt_thumb_dir() . $vtId . '.png';
}

/**
 * Vygeneruje náhled mapy virtuální trasy (linie mezi body fotek) do
 * uploads/vt_thumbs/<id>.png. Reuse generate_thumb() přes dočasné minimální GPX.
 */
function vt_generate_thumb(\PDO $pdo, int $vtId): bool {
    $stmt = $pdo->prepare("
        SELECT lat, lon FROM track_photos
        WHERE virtual_track_id = ? AND lat IS NOT NULL AND lon IS NOT NULL
        ORDER BY taken_at ASC, id ASC
    ");
    $stmt->execute([$vtId]);
    $pts = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    if (count($pts) < 2) {
        // Trasa už nemá dost bodů — starý náhled by neodpovídal, smazat
        @unlink(vt_thumb_path($vtId));
        return false;
    }

    $dir = vt_thumb_dir();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    // Dočasné minimální GPX z bodů fotek
    $gpx = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
         . '<gpx version="1.1" creator="GPX Manager" xmlns="http://www.topografix.com/GPX/1/1">' . "\n"
         . '<trk><trkseg>' . "\n";
    foreach ($pts as $p) {
        $gpx .= '<trkpt lat="' . (float)$p['lat'] . '" lon="' . (float)$p['lon'] . '"></trkpt>' . "\n";
    }
    $gpx .= '</trkseg></trk></gpx>';

    $tmp = tempnam(sys_get_temp_dir(), 'vtgpx_');
    if ($tmp === false) return false;
    $tmpGpx = $tmp . '.gpx';
    @rename($tmp, $tmpGpx);
    file_put_contents($tmpGpx, $gpx);

    $ok = generate_thumb($tmpGpx, vt_thumb_path($vtId));
    @unlink($tmpGpx);
    return $ok;
}

/**
 * Vrátí nepřiřazené fotky vhodné pro tvorbu virtuálních tras
 * (bez GPX trasy i bez virtuální trasy, s GPS i časem), seřazené taken_at ASC.
 *
 * @return array<int,array<string,mixed>>
 */
function vt_fetch_candidates(\PDO $pdo): array
{
    return $pdo->query("
        SELECT id, lat, lon, altitude, taken_at, filename, orig_name, caption
        FROM track_photos
        WHERE track_id IS NULL AND virtual_track_id IS NULL
          AND lat IS NOT NULL AND lon IS NOT NULL AND taken_at IS NOT NULL
        ORDER BY taken_at ASC, id ASC
    ")->fetchAll(\PDO::FETCH_ASSOC);
}

/**
 * Vrátí fotky JEDNÉ virtuální trasy (s GPS i časem), seřazené taken_at ASC —
 * vstup pro re-split (rozdělení existující trasy na úseky).
 *
 * @return array<int,array<string,mixed>>
 */
function vt_fetch_track_photos(\PDO $pdo, int $vtId): array
{
    $stmt = $pdo->prepare("
        SELECT id, lat, lon, altitude, taken_at
        FROM track_photos
        WHERE virtual_track_id = :vt
          AND lat IS NOT NULL AND lon IS NOT NULL AND taken_at IS NOT NULL
        ORDER BY taken_at ASC, id ASC
    ");
    $stmt->execute([':vt' => $vtId]);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

/**
 * Shlukne fotky do výletů podle času i vzdálenosti.
 *
 * Nový shluk začne, když je vůči předchozí fotce:
 *   - časová mezera > $gapHours hodin, NEBO
 *   - vzdálenost > $gapKm km (velký skok v poloze).
 * Shluky s méně než $minPhotos fotkami se vrátí zvlášť jako „rejected".
 *
 * @param array $photos    fotky seřazené taken_at ASC (s klíči lat, lon, taken_at)
 * @return array{clusters: array<int,array>, rejected: array<int,array>}
 */
function vt_cluster_photos(array $photos, float $gapHours, float $gapKm, int $minPhotos): array
{
    $clusters = [];
    $current  = [];
    $prev     = null;

    foreach ($photos as $p) {
        if ($prev !== null) {
            $dtHours = (strtotime((string)$p['taken_at']) - strtotime((string)$prev['taken_at'])) / 3600.0;
            $distKm  = haversine((float)$prev['lat'], (float)$prev['lon'], (float)$p['lat'], (float)$p['lon']) / 1000.0;
            if ($dtHours > $gapHours || $distKm > $gapKm) {
                $clusters[] = $current;
                $current = [];
            }
        }
        $current[] = $p;
        $prev = $p;
    }
    if ($current) {
        $clusters[] = $current;
    }

    $accepted = [];
    $rejected = [];
    foreach ($clusters as $c) {
        if (count($c) >= $minPhotos) {
            $accepted[] = $c;
        } else {
            $rejected = array_merge($rejected, $c);
        }
    }
    return ['clusters' => $accepted, 'rejected' => $rejected];
}

/**
 * Rozdělí fotky JEDNÉ trasy na úseky podle času i polohy (stejné zlomy jako
 * vt_cluster_photos), ale úseky s méně než $minPhotos fotkami se NEZAHODÍ —
 * přilepí se k časově nejbližšímu sousednímu úseku (žádná fotka se neztratí).
 *
 * Používá se pro „online" doladění už vytvořené virtuální trasy: vstupní fotky
 * jsou souvislá časová řada, takže výsledkem je vždy 1..N úseků pokrývajících
 * úplně všechny vstupní fotky.
 *
 * @param array $photos seřazené taken_at ASC (klíče lat, lon, taken_at)
 * @return array<int,array<int,array>> úseky (každý aspoň 1 fotka)
 */
function vt_split_photos(array $photos, float $gapHours, float $gapKm, int $minPhotos): array
{
    if (count($photos) <= 1) {
        return $photos ? [array_values($photos)] : [];
    }

    // 1) Hrubé úseky podle zlomů (čas NEBO skok v poloze) — nic se nezahazuje.
    $segments = [];
    $current  = [];
    $prev     = null;
    foreach ($photos as $p) {
        if ($prev !== null) {
            $dtHours = (strtotime((string)$p['taken_at']) - strtotime((string)$prev['taken_at'])) / 3600.0;
            $distKm  = haversine((float)$prev['lat'], (float)$prev['lon'], (float)$p['lat'], (float)$p['lon']) / 1000.0;
            if ($dtHours > $gapHours || $distKm > $gapKm) {
                $segments[] = $current;
                $current = [];
            }
        }
        $current[] = $p;
        $prev = $p;
    }
    if ($current) {
        $segments[] = $current;
    }

    // 2) Malé úseky (< minPhotos) přilepit k časově nejbližšímu sousedovi.
    //    Každá iterace odebere jeden úsek → smyčka vždy skončí.
    $lastTaken  = static fn(array $seg): int => (int)strtotime((string)$seg[count($seg) - 1]['taken_at']);
    $firstTaken = static fn(array $seg): int => (int)strtotime((string)$seg[0]['taken_at']);

    while (count($segments) > 1 && $minPhotos > 1) {
        $smallIdx = -1;
        foreach ($segments as $i => $seg) {
            if (count($seg) < $minPhotos) { $smallIdx = $i; break; }
        }
        if ($smallIdx === -1) {
            break; // všechny úseky jsou dost velké
        }

        // soused: jediný možný (kraj), jinak časově bližší
        if ($smallIdx === 0) {
            $target = 1;
        } elseif ($smallIdx === count($segments) - 1) {
            $target = $smallIdx - 1;
        } else {
            $gapPrev = $firstTaken($segments[$smallIdx]) - $lastTaken($segments[$smallIdx - 1]);
            $gapNext = $firstTaken($segments[$smallIdx + 1]) - $lastTaken($segments[$smallIdx]);
            $target  = ($gapPrev <= $gapNext) ? $smallIdx - 1 : $smallIdx + 1;
        }

        // spojit v časovém pořadí (dřívější úsek první)
        if ($target < $smallIdx) {
            $segments[$target] = array_merge($segments[$target], $segments[$smallIdx]);
        } else {
            $segments[$target] = array_merge($segments[$smallIdx], $segments[$target]);
        }
        array_splice($segments, $smallIdx, 1);
    }

    return $segments;
}

/**
 * Spočítá statistiky virtuální trasy z bodů shluku.
 * distance_km je přibližná (vzdušná čára mezi fotkami). ascent/descent z altitude
 * s hysterezí GPX_ELEVATION_NOISE_M, jen pokud altitude existuje (jinak null).
 *
 * @param array $clusterPhotos fotky seřazené taken_at ASC
 * @return array<string,mixed>
 */
function vt_compute_stats(array $clusterPhotos): array
{
    $n = count($clusterPhotos);
    $lats = [];
    $lons = [];
    $distM = 0.0;
    $ascent = 0.0;
    $descent = 0.0;
    $eleRef = null;
    $prev = null;

    foreach ($clusterPhotos as $p) {
        $lat = (float)$p['lat'];
        $lon = (float)$p['lon'];
        $lats[] = $lat;
        $lons[] = $lon;

        if ($prev !== null) {
            $distM += haversine((float)$prev['lat'], (float)$prev['lon'], $lat, $lon);
        }

        // ascent/descent z altitude s hysterezí
        if (isset($p['altitude']) && $p['altitude'] !== null && $p['altitude'] !== '') {
            $ele = (float)$p['altitude'];
            if ($eleRef === null) {
                $eleRef = $ele;
            } else {
                $d = $ele - $eleRef;
                if ($d >= GPX_ELEVATION_NOISE_M) {
                    $ascent += $d;
                    $eleRef = $ele;
                } elseif ($d <= -GPX_ELEVATION_NOISE_M) {
                    $descent += -$d;
                    $eleRef = $ele;
                }
            }
        }
        $prev = $p;
    }

    $minLat = min($lats);
    $maxLat = max($lats);
    $minLon = min($lons);
    $maxLon = max($lons);
    $dateStart = (string)$clusterPhotos[0]['taken_at'];
    $dateEnd   = (string)$clusterPhotos[$n - 1]['taken_at'];
    $hasAlt    = $eleRef !== null;

    $name = 'Virtuální trasa — ' . date('j. n. Y', (int)strtotime($dateStart)) . " ({$n} fotek)";

    return [
        'name'         => $name,
        'date_start'   => $dateStart,
        'date_end'     => $dateEnd,
        'photo_count'  => $n,
        'distance_km'  => round($distM / 1000.0, 2),
        'ascent'       => $hasAlt ? round($ascent, 0) : null,
        'descent'      => $hasAlt ? round($descent, 0) : null,
        'bounds'       => json_encode([
            'minlat' => $minLat, 'maxlat' => $maxLat,
            'minlon' => $minLon, 'maxlon' => $maxLon,
        ], JSON_UNESCAPED_SLASHES),
        'centroid_lat' => ($minLat + $maxLat) / 2.0,
        'centroid_lon' => ($minLon + $maxLon) / 2.0,
    ];
}

/**
 * Přepočítá a uloží statistiky virtuální trasy z jejích fotek
 * (po posunu/úpravě polohy fotky, přiřazení/odebrání/smazání fotky).
 * Název (name) se NEMĚNÍ.
 * Pokud trasa už nemá žádné GPS body, geometrii vynuluje (photo_count
 * zůstane počtem zbylých fotek bez GPS) a vrátí null.
 *
 * @return array<string,mixed>|null
 */
function vt_recompute_and_save(\PDO $pdo, int $vtId): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, lat, lon, altitude, taken_at
        FROM track_photos
        WHERE virtual_track_id = ? AND lat IS NOT NULL AND lon IS NOT NULL
        ORDER BY taken_at ASC, id ASC
    ");
    $stmt->execute([$vtId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    if (!$rows) {
        // Žádné GPS body — geometrii vynulovat, ať v přehledu nezůstanou
        // zastaralé statistiky. photo_count = fotky bez GPS (může být > 0),
        // datumy z taken_at zbývajících fotek (pokud nějaké mají čas).
        $meta = $pdo->prepare("
            SELECT COUNT(*) AS cnt, MIN(taken_at) AS dmin, MAX(taken_at) AS dmax
            FROM track_photos WHERE virtual_track_id = ?
        ");
        $meta->execute([$vtId]);
        $m = $meta->fetch(\PDO::FETCH_ASSOC);
        $pdo->prepare("
            UPDATE virtual_tracks SET
                date_start = :date_start, date_end = :date_end, photo_count = :photo_count,
                distance_km = NULL, ascent = NULL, descent = NULL,
                bounds = NULL, centroid_lat = NULL, centroid_lon = NULL
            WHERE id = :id
        ")->execute([
            ':date_start'  => $m['dmin'],
            ':date_end'    => $m['dmax'],
            ':photo_count' => (int)$m['cnt'],
            ':id'          => $vtId,
        ]);
        return null;
    }
    $s = vt_compute_stats($rows);

    // photo_count = všechny fotky trasy (vč. ručně přiřazených bez GPS),
    // ne jen body, ze kterých se počítá geometrie.
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM track_photos WHERE virtual_track_id = ?");
    $cntStmt->execute([$vtId]);
    $totalPhotos = (int)$cntStmt->fetchColumn();
    if ($totalPhotos > 0) {
        $s['photo_count'] = $totalPhotos;
    }

    $pdo->prepare("
        UPDATE virtual_tracks SET
            date_start = :date_start, date_end = :date_end, photo_count = :photo_count,
            distance_km = :distance_km, ascent = :ascent, descent = :descent,
            bounds = :bounds, centroid_lat = :centroid_lat, centroid_lon = :centroid_lon
        WHERE id = :id
    ")->execute([
        ':date_start'   => $s['date_start'],
        ':date_end'     => $s['date_end'],
        ':photo_count'  => $s['photo_count'],
        ':distance_km'  => $s['distance_km'],
        ':ascent'       => $s['ascent'],
        ':descent'      => $s['descent'],
        ':bounds'       => $s['bounds'],
        ':centroid_lat' => $s['centroid_lat'],
        ':centroid_lon' => $s['centroid_lon'],
        ':id'           => $vtId,
    ]);
    return $s;
}

/**
 * Reverse-geocode jednoho bodu přes Mapy.com → název obce (regional.municipality),
 * nebo null když se nepodaří. Server-side (API klíč zůstává na serveru).
 * Reuse curl vzoru z api/virtual_tracks/route.php (CURL_CA_BUNDLE pro lokální WAMP).
 */
function vt_reverse_geocode_municipality(float $lat, float $lon): ?string
{
    if (!defined('MAPYCOM_API_KEY') || MAPYCOM_API_KEY === '') {
        return null;
    }
    $url = 'https://api.mapy.com/v1/rgeocode?' . http_build_query([
        'lat'    => $lat,
        'lon'    => $lon,
        'lang'   => 'cs',
        'apikey' => MAPYCOM_API_KEY,
    ]);

    $body = false;
    if (function_exists('curl_init')) {
        $ch   = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ];
        if (defined('CURL_CA_BUNDLE') && CURL_CA_BUNDLE !== '' && is_file(CURL_CA_BUNDLE)) {
            $opts[CURLOPT_CAINFO] = CURL_CA_BUNDLE;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $http >= 400) {
            return null;
        }
    } else {
        $ctx  = stream_context_create(['http' => ['timeout' => 15, 'header' => "Accept: application/json\r\n"]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return null;
        }
    }

    $data  = json_decode((string)$body, true);
    $parts = $data['items'][0]['regionalStructure'] ?? null;
    if (!is_array($parts)) {
        return null;
    }
    foreach ($parts as $part) {
        if (($part['type'] ?? '') === 'regional.municipality' && !empty($part['name'])) {
            return trim((string)$part['name']);
        }
    }
    return null;
}

/**
 * Vybere až $max bodů rovnoměrně rozložených podél (chronologického) pole bodů.
 * Vždy zahrne první a poslední.
 *
 * @param array $points body (klíče lat, lon)
 * @return array<int,array<string,mixed>>
 */
function vt_sample_points(array $points, int $max = 5): array
{
    $points = array_values($points);
    $n = count($points);
    if ($n <= $max || $max < 2) {
        return $points;
    }
    $idx = [];
    for ($i = 0; $i < $max; $i++) {
        $idx[(int)round($i * ($n - 1) / ($max - 1))] = true;
    }
    $out = [];
    foreach (array_keys($idx) as $i) {
        $out[] = $points[$i];
    }
    return $out;
}

/**
 * Posbírá až $maxPlaces různých obcí podél trasy (v chronologickém pořadí výskytu).
 *
 * @param array $points body seřazené chronologicky (klíče lat, lon)
 * @return array<int,string>
 */
function vt_collect_places(array $points, int $maxPlaces = 2): array
{
    $places = [];
    foreach (vt_sample_points($points, 5) as $p) {
        if (!isset($p['lat'], $p['lon'])) {
            continue;
        }
        $m = vt_reverse_geocode_municipality((float)$p['lat'], (float)$p['lon']);
        if ($m !== null && $m !== '' && !in_array($m, $places, true)) {
            $places[] = $m;
            if (count($places) >= $maxPlaces) {
                break;
            }
        }
    }
    return $places;
}

/**
 * Navrhne název virtuální trasy podle hlavních míst (obcí) na trase.
 * Formát: "Virtuální trasa — Místo1, Místo2 - j. n. Y (N fotek)".
 * Když se nepodaří zjistit žádné místo → fallback na datum-only formát
 * (shodný s názvem z vt_compute_stats()).
 *
 * @param array  $points     body trasy (chronologicky; klíče lat, lon)
 * @param string $dateStart  datum začátku (pro datovou část názvu)
 * @param int    $photoCount počet fotek
 */
function vt_suggest_name_from_points(array $points, string $dateStart, int $photoCount, int $maxPlaces = 2): string
{
    $datePart = date('j. n. Y', (int)strtotime($dateStart));
    $places   = vt_collect_places($points, $maxPlaces);
    if ($places) {
        return 'Virtuální trasa — ' . implode(', ', $places) . ' - ' . $datePart . " ({$photoCount} fotek)";
    }
    return 'Virtuální trasa — ' . $datePart . " ({$photoCount} fotek)";
}

/**
 * Rozparsuje hodnotu z přiřazovacího selectu na cíl fotky.
 *   ''   → [null, null]  (nepřiřadit)
 *   'N'  → [N, null]      (GPX trasa)
 *   'vN' → [null, N]      (virtuální trasa)
 *
 * track_id a virtual_track_id jsou vzájemně výlučné — vrácená dvojice vždy
 * obsahuje nejvýš jednu nenulovou hodnotu.
 *
 * @return array{0:?int,1:?int} [track_id, virtual_track_id]
 */
function vt_parse_assign_target($raw): array
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return [null, null];
    }
    if ($raw[0] === 'v' || $raw[0] === 'V') {
        $id = (int)substr($raw, 1);
        return $id > 0 ? [null, $id] : [null, null];
    }
    $id = (int)$raw;
    return $id > 0 ? [$id, null] : [null, null];
}
