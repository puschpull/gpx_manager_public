<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
// gpx_parser.php removed — nearby AJAX now uses centroid BBOX, no GPX parsing (TASK-11)

$allowedLangs = available_langs();

// How many nearest tracks the user may ask for (UI select offers 4–20)
const NEARBY_LIMIT_MIN = 4;
const NEARBY_LIMIT_MAX = 20;

// ====== ZIP export of the found tracks ======
// GET nearby.php?export=zip&ids=12,34,56 — read-only download, same access
// as the page itself (check_page_access('nearby') ran in nearby.php). The GPX
// files are public anyway (detail.php links them directly).
if (isset($_GET['export']) && $_GET['export'] === 'zip') {
    $ids = array_values(array_unique(array_filter(
        array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))),
        static fn(int $id) => $id > 0
    )));
    $ids = array_slice($ids, 0, NEARBY_LIMIT_MAX);

    if (!$ids) {
        http_response_code(400);
        exit('No tracks selected.');
    }
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        exit('PHP ZipArchive is not available.');
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, filename FROM tracks WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $byId = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byId[(int)$row['id']] = (string)$row['filename'];
    }

    $tmpZip = tempnam(sys_get_temp_dir(), 'gpxnb');
    $zip    = new ZipArchive();
    if ($tmpZip === false || $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        exit('Could not create ZIP archive.');
    }

    // Keep the order of the request (= nearest first); skip missing files
    $added = 0;
    $used  = [];
    foreach ($ids as $id) {
        if (!isset($byId[$id])) continue;
        $path = uploads_fs($byId[$id]);
        if (!is_file($path)) {
            error_log('nearby zip: missing GPX file ' . $byId[$id]);
            continue;
        }
        $entry = basename($byId[$id]);
        if (isset($used[$entry])) $entry = $id . '_' . $entry;
        $used[$entry] = true;
        $zip->addFile($path, $entry);
        $added++;
    }
    $zip->close();

    if ($added === 0) {
        @unlink($tmpZip);
        http_response_code(404);
        exit('No GPX file found on disk.');
    }

    $zipName = 'trasy_v_okoli_' . date('Y-m-d_His') . '_(' . $added . ').zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($tmpZip));
    header('Cache-Control: no-store');
    readfile($tmpZip);
    unlink($tmpZip);
    exit;
}

// ====== AJAX endpoint ======
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json; charset=utf-8');

    // SEC-026: Per-IP rate limit — nearby AJAX parses GPX files and is CPU-intensive
    $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $rateFile   = sys_get_temp_dir() . '/gpx_nearby_' . md5($ip) . '.txt';
    $now        = time();
    $rateWindow = 60;  // sliding 1-minute window
    $maxReq     = 20;
    $hits = is_file($rateFile)
        ? array_filter(
            explode("\n", file_get_contents($rateFile)),
            static fn($t) => (int)$t > $now - $rateWindow
          )
        : [];
    if (count($hits) >= $maxReq) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many requests. Please wait a moment.']);
        exit;
    }
    $hits[] = (string)$now;
    file_put_contents($rateFile, implode("\n", array_values($hits)), LOCK_EX);

    $clickLat = (float)($_GET['lat'] ?? 0);
    $clickLon = (float)($_GET['lon'] ?? 0);

    if ($clickLat == 0 && $clickLon == 0) {
        echo json_encode(['error' => 'Chybí souřadnice bodu.']);
        exit;
    }

    $limit = max(NEARBY_LIMIT_MIN, min(NEARBY_LIMIT_MAX, (int)($_GET['limit'] ?? 8)));

    // ====== Refactored: SQL BBOX pre-filter na centroid (PERF-8, BE-12, DB-10) ======
    // GPX parsing bylo O(30 souborů) per request — eliminováno.
    // Nový flow:
    //   1. SQL BBOX filter na centroid ~50 km radius, LIMIT 100
    //   2. Haversine od centroidu v PHP → usort → top 30
    //   3. Pro trasy bez centroidu: fallback na bounds JSON střed
    // Výsledný distance_from_point je vzdálenost centroidu od kliknutého bodu.

    $radiusKm = 50.0; // pevný poloměr pro nearby search
    $latDelta = $radiusKm / 111.0;
    $lonDelta = $radiusKm / (111.0 * max(cos(deg2rad($clickLat)), 0.01));

    $latMin = $clickLat - $latDelta;
    $latMax = $clickLat + $latDelta;
    $lonMin = $clickLon - $lonDelta;
    $lonMax = $clickLon + $lonDelta;

    // Kandidáti z SQL: trasy s centroidem v BBOX (nebo s bounds fallback).
    // KLÍČOVÉ: řazení podle blízkosti centroidu ke kliknutému bodu PŘED LIMIT,
    // jinak MySQL vrátí náhodných 100 a opravdu blízké trasy vypadnou.
    // Trasy bez centroidu (NULL) se řadí až za ty s centroidem.
    $stmt = $pdo->prepare("
        SELECT id, filename, track_name, distance_km, ascent, descent,
               date_start, duration, bounds, color, centroid_lat, centroid_lon
        FROM tracks
        WHERE (
              (centroid_lat BETWEEN :latMin AND :latMax
               AND centroid_lon BETWEEN :lonMin AND :lonMax)
              OR (centroid_lat IS NULL AND bounds IS NOT NULL AND bounds != '')
        )
        ORDER BY
            (centroid_lat IS NULL),
            (POW(centroid_lat - :clickLat, 2)
             + POW((centroid_lon - :clickLon) * COS(RADIANS(:clickLat2)), 2)) ASC
        LIMIT 100
    ");
    $stmt->execute([
        ':latMin'    => $latMin,
        ':latMax'    => $latMax,
        ':lonMin'    => $lonMin,
        ':lonMax'    => $lonMax,
        ':clickLat'  => $clickLat,
        ':clickLat2' => $clickLat,
        ':clickLon'  => $clickLon,
    ]);
    $allTracks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Výpočet vzdálenosti centroidu (nebo středu bounds) od kliknutého bodu
    $candidates = [];
    foreach ($allTracks as $t) {
        $tCenterLat = isset($t['centroid_lat']) && $t['centroid_lat'] !== null
            ? (float)$t['centroid_lat']
            : null;
        $tCenterLon = isset($t['centroid_lon']) && $t['centroid_lon'] !== null
            ? (float)$t['centroid_lon']
            : null;

        if ($tCenterLat === null) {
            // Fallback: spočítej střed z bounds JSON
            $b = json_decode((string)($t['bounds'] ?? ''), true);
            if (!$b || !isset($b['minlat'], $b['maxlat'], $b['minlon'], $b['maxlon'])) continue;
            $tCenterLat = ((float)$b['minlat'] + (float)$b['maxlat']) / 2.0;
            $tCenterLon = ((float)$b['minlon'] + (float)$b['maxlon']) / 2.0;
        }

        $dist = haversine($clickLat, $clickLon, $tCenterLat, $tCenterLon);
        $t['_dist_m']      = $dist;          // prozatím vzdálenost k centroidu
        $t['_near_lat']    = $tCenterLat;    // fallback, pokud GPX nejde načíst
        $t['_near_lon']    = $tCenterLon;
        $candidates[] = $t;
    }

    // Předřazení podle centroidu, vzít top kandidáty (aspoň 30, u většího
    // limitu dvojnásobek — přesná vzdálenost pořadí ještě přehází)
    usort($candidates, fn($a, $b) => $a['_dist_m'] <=> $b['_dist_m']);
    $candidates = array_slice($candidates, 0, max(30, $limit * 2));

    // Přesný výpočet: u top 30 kandidátů najdi SKUTEČNÝ nejbližší bod trasy
    // (parsování GPX, jako v původní verzi před TASK-11). Centroid sloužil jen
    // k předfiltru — zobrazená vzdálenost teď odpovídá nejbližšímu bodu trasy.
    foreach ($candidates as &$cand) {
        $gpxPath = uploads_fs($cand['filename']);
        if (!is_file($gpxPath)) continue;

        $xml = safe_load_gpx($gpxPath);
        if (!$xml) continue;
        $xml->registerXPathNamespace('g', 'http://www.topografix.com/GPX/1/1');
        $pts = $xml->xpath('//g:trkpt');
        if (!$pts) continue;

        $n    = count($pts);
        $step = $n > 3000 ? 3 : 1;   // hustě vzorkované trasy prořídit kvůli výkonu
        $bestDist = $cand['_dist_m'];
        $bestLat  = $cand['_near_lat'];
        $bestLon  = $cand['_near_lon'];
        for ($i = 0; $i < $n; $i += $step) {
            $plat = (float)$pts[$i]['lat'];
            $plon = (float)$pts[$i]['lon'];
            $d = haversine($clickLat, $clickLon, $plat, $plon);
            if ($d < $bestDist) {
                $bestDist = $d;
                $bestLat  = $plat;
                $bestLon  = $plon;
            }
        }
        $cand['_dist_m']   = $bestDist;
        $cand['_near_lat'] = $bestLat;
        $cand['_near_lon'] = $bestLon;
    }
    unset($cand);

    // Přeřadit podle skutečné vzdálenosti k nejbližšímu bodu
    usort($candidates, fn($a, $b) => $a['_dist_m'] <=> $b['_dist_m']);

    // Sestavení výsledků — nearest_lat/lon = skutečný nejbližší bod trasy
    $results = [];
    foreach ($candidates as $t) {
        $results[] = [
            'id'                  => (int)$t['id'],
            'filename'            => $t['filename'],
            'gpx_url'             => gpx_url((string)$t['filename']),
            'track_name'          => $t['track_name'],
            'distance_km'         => round((float)$t['distance_km'], 2),
            'ascent'              => round((float)$t['ascent'], 0),
            'descent'             => round((float)$t['descent'], 0),
            'date_start'          => $t['date_start'],
            'duration'            => (int)$t['duration'],
            'color'               => $t['color'],
            'nearest_lat'         => round((float)$t['_near_lat'], 6),
            'nearest_lon'         => round((float)$t['_near_lon'], 6),
            'distance_from_point' => round($t['_dist_m'] / 1000, 2), // metry → km
        ];
    }

    // Finální top N (kandidáti už jsou seřazeni podle skutečné vzdálenosti)
    $results = array_slice($results, 0, $limit);

    echo json_encode(['tracks' => $results], JSON_UNESCAPED_UNICODE);
    exit;
}
