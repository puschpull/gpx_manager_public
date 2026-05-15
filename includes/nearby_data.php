<?php
require_once __DIR__ . '/../includes/helpers.php';
// gpx_parser.php removed — nearby AJAX now uses centroid BBOX, no GPX parsing (TASK-11)

// ====== Stylování ======
init_theme_cookie();
$theme = active_theme();
$available_themes = available_themes();
$allowedLangs = available_langs();

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

    // Kandidáti z SQL: trasy s centroidem v BBOX nebo s bounds (fallback) v BBOX
    $stmt = $pdo->prepare("
        SELECT id, filename, track_name, distance_km, ascent, descent,
               date_start, duration, bounds, color, centroid_lat, centroid_lon
        FROM tracks
        WHERE (
              (centroid_lat BETWEEN :latMin AND :latMax
               AND centroid_lon BETWEEN :lonMin AND :lonMax)
              OR (centroid_lat IS NULL AND bounds IS NOT NULL AND bounds != '')
        )
        LIMIT 100
    ");
    $stmt->execute([
        ':latMin' => $latMin,
        ':latMax' => $latMax,
        ':lonMin' => $lonMin,
        ':lonMax' => $lonMax,
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
        $t['_dist_m'] = $dist;
        $candidates[] = $t;
    }

    // Seřadit podle vzdálenosti, vzít top 30 pro PHP haversine re-rank
    usort($candidates, fn($a, $b) => $a['_dist_m'] <=> $b['_dist_m']);
    $candidates = array_slice($candidates, 0, 30);

    // Sestavení výsledků — nearest_lat/lon = centroid (není to closest trackpoint,
    // ale je to O(1) místo O(trackpoints); pro display purposes dostačující)
    $results = [];
    foreach ($candidates as $t) {
        $tCenterLat = isset($t['centroid_lat']) && $t['centroid_lat'] !== null
            ? (float)$t['centroid_lat']
            : null;
        $tCenterLon = isset($t['centroid_lon']) && $t['centroid_lon'] !== null
            ? (float)$t['centroid_lon']
            : null;

        if ($tCenterLat === null) {
            $b = json_decode((string)($t['bounds'] ?? ''), true);
            if (!$b || !isset($b['minlat'], $b['maxlat'], $b['minlon'], $b['maxlon'])) continue;
            $tCenterLat = ((float)$b['minlat'] + (float)$b['maxlat']) / 2.0;
            $tCenterLon = ((float)$b['minlon'] + (float)$b['maxlon']) / 2.0;
        }

        $results[] = [
            'id'                  => (int)$t['id'],
            'filename'            => $t['filename'],
            'track_name'          => $t['track_name'],
            'distance_km'         => round((float)$t['distance_km'], 2),
            'ascent'              => round((float)$t['ascent'], 0),
            'descent'             => round((float)$t['descent'], 0),
            'date_start'          => $t['date_start'],
            'duration'            => (int)$t['duration'],
            'color'               => $t['color'],
            'nearest_lat'         => round($tCenterLat, 6),
            'nearest_lon'         => round($tCenterLon, 6),
            'distance_from_point' => round($t['_dist_m'] / 1000, 2), // metry → km
        ];
    }

    // Finální řazení + top 8
    usort($results, fn($a, $b) => $a['distance_from_point'] <=> $b['distance_from_point']);
    $results = array_slice($results, 0, 8);

    echo json_encode(['tracks' => $results], JSON_UNESCAPED_UNICODE);
    exit;
}
