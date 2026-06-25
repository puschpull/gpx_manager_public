<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/gpx_parser.php';

// ====== AJAX: Podobné trasy ======
if (isset($_GET['ajax']) && $_GET['ajax'] === 'similar') {
    require_once __DIR__ . '/../includes/db.php';
    header('Content-Type: application/json; charset=utf-8');

    $trackId = (int)($_GET['id'] ?? 0);
    if ($trackId <= 0) {
        echo json_encode(['error' => 'Neplatné ID.']);
        exit;
    }

    // Načtení aktuální trasy
    $stmt = $pdo->prepare('SELECT id, bounds, distance_km, ascent, difficulty, centroid_lat, centroid_lon FROM tracks WHERE id = ?');
    $stmt->execute([$trackId]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$current) {
        echo json_encode(['error' => 'Trasa nenalezena.']);
        exit;
    }

    $curBounds = json_decode($current['bounds'] ?? '', true);
    $curDist   = (float)($current['distance_km'] ?? 0);
    $curAsc    = (float)($current['ascent'] ?? 0);
    $curDiff   = (int)($current['difficulty'] ?? 0);

    // Střed bounding boxu / centroid aktuální trasy
    // Preferujeme centroid_lat/lon sloupec (TASK-11); fallback na bounds JSON.
    $curCenterLat = isset($current['centroid_lat']) && $current['centroid_lat'] !== null
        ? (float)$current['centroid_lat']
        : null;
    $curCenterLon = isset($current['centroid_lon']) && $current['centroid_lon'] !== null
        ? (float)$current['centroid_lon']
        : null;

    if ($curCenterLat === null && $curBounds && isset($curBounds['minlat'], $curBounds['maxlat'], $curBounds['minlon'], $curBounds['maxlon'])) {
        $curCenterLat = ((float)$curBounds['minlat'] + (float)$curBounds['maxlat']) / 2.0;
        $curCenterLon = ((float)$curBounds['minlon'] + (float)$curBounds['maxlon']) / 2.0;
    }

    // ====== SQL pre-filter na centroid BBOX ~100 km (DB-9, PERF-3) ======
    // Načítáme max 100 kandidátů místo celé tabulky.
    // Fallback (centroid_lat IS NULL): původní dotaz bez BBOX, LIMIT 200.
    if ($curCenterLat !== null) {
        // 1 stupeň lat ≈ 111 km; 100 km radius
        $deltaLat = 100.0 / 111.0;
        $deltaLon = 100.0 / (111.0 * max(cos(deg2rad($curCenterLat)), 0.01));

        $latMin = $curCenterLat - $deltaLat;
        $latMax = $curCenterLat + $deltaLat;
        $lonMin = $curCenterLon - $deltaLon;
        $lonMax = $curCenterLon + $deltaLon;

        $allStmt = $pdo->prepare("
            SELECT id, filename, track_name, distance_km, ascent, descent,
                   date_start, duration, bounds, color, difficulty, activity_type,
                   centroid_lat, centroid_lon
            FROM tracks
            WHERE id != :tid
              AND (
                    (centroid_lat BETWEEN :latMin AND :latMax
                     AND centroid_lon BETWEEN :lonMin AND :lonMax)
                    OR centroid_lat IS NULL
              )
              AND (bounds IS NOT NULL AND bounds != '')
            LIMIT 100
        ");
        $allStmt->execute([
            ':tid'    => $trackId,
            ':latMin' => $latMin,
            ':latMax' => $latMax,
            ':lonMin' => $lonMin,
            ':lonMax' => $lonMax,
        ]);
    } else {
        // Fallback: centroid neznámý — omezíme alespoň na LIMIT 200
        $allStmt = $pdo->prepare("
            SELECT id, filename, track_name, distance_km, ascent, descent,
                   date_start, duration, bounds, color, difficulty, activity_type,
                   centroid_lat, centroid_lon
            FROM tracks
            WHERE id != :tid AND bounds IS NOT NULL AND bounds != ''
            LIMIT 200
        ");
        $allStmt->execute([':tid' => $trackId]);
    }
    $allTracks = $allStmt->fetchAll(PDO::FETCH_ASSOC);

    // Skóre podobnosti pro každou trasu
    $scored = [];
    foreach ($allTracks as $t) {
        // Centroid kandidáta: preferuj centroid_lat/lon sloupec, fallback bounds JSON
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

        $score = 0;

        // 1. Geografická blízkost (centroid vs. centroid) — max 50 bodů
        if ($curCenterLat !== null && $tCenterLat !== null) {
            $distKm = haversine($curCenterLat, $curCenterLon, $tCenterLat, $tCenterLon) / 1000;
            // Čím blíž, tím víc bodů (50 bodů za 0 km, 0 bodů za 100+ km)
            $score += max(0, 50 - ($distKm * 0.5));
        }

        // 2. Podobná vzdálenost — max 25 bodů
        $tDist = (float)$t['distance_km'];
        if ($curDist > 0 && $tDist > 0) {
            $ratio = min($curDist, $tDist) / max($curDist, $tDist); // 0-1
            $score += $ratio * 25;
        }

        // 3. Podobná obtížnost — max 15 bodů
        $tDiff = (int)($t['difficulty'] ?? 0);
        if ($curDiff > 0 && $tDiff > 0) {
            $diffDelta = abs($curDiff - $tDiff);
            $score += max(0, 15 - ($diffDelta * 5));
        }

        // 4. Podobné stoupání — max 10 bodů
        $tAsc = (float)$t['ascent'];
        if ($curAsc > 0 && $tAsc > 0) {
            $ratio = min($curAsc, $tAsc) / max($curAsc, $tAsc);
            $score += $ratio * 10;
        }

        $scored[] = [
            'id'            => (int)$t['id'],
            'filename'      => $t['filename'],
            'track_name'    => $t['track_name'],
            'distance_km'   => round((float)$t['distance_km'], 2),
            'ascent'        => round((float)$t['ascent'], 0),
            'descent'       => round((float)$t['descent'], 0),
            'date_start'    => $t['date_start'],
            'duration'      => (int)$t['duration'],
            'difficulty'    => (int)($t['difficulty'] ?? 0),
            'activity_type' => $t['activity_type'],
            'score'         => round($score, 1),
        ];
    }

    // Seřadit podle skóre (nejvyšší = nejpodobnější)
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

    // Vrátit top 6
    $results = array_slice($scored, 0, 6);

    echo json_encode(['tracks' => $results], JSON_UNESCAPED_UNICODE);
    exit;
}

$allowedLangs = available_langs();

// ====== Načtení dat o trase ======
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) die('Neplatné ID.');

$stmt = $pdo->prepare('SELECT * FROM tracks WHERE id = ?');
$stmt->execute([$id]);
$track = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$track) die('Trasa nenalezena.');

// ====== Pomocné proměnné ======
$track_name = h($track['track_name'] ?: $track['filename']);
$BASE_URL   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

// ====== Navigace — předchozí / následující ======
$filterActive = isset($_GET['filter_submit']);

// Řazení — stejná logika jako v index_data.php
$sortOptions = [
    'id', 'filename', 'track_name', 'alt_title', 'note', 'color', 'device',
    'date_start', 'date_end', 'duration', 'moving_time', 'stopped_time',
    'distance_km', 'ascent', 'descent', 'elevation_min', 'elevation_max',
    'speed_max', 'speed_avg', 'speed_avg_total',
    'avg_ascent_rate', 'avg_descent_rate', 'max_ascent_rate', 'max_descent_rate',
    'trackpoints_count', 'difficulty', 'is_favorite', 'created_at',
];

$sort_by  = $_GET['sort_by']  ?? 'date_start';
$sort_dir = strtoupper($_GET['sort_dir'] ?? 'DESC');
if (!in_array($sort_by, $sortOptions, true)) $sort_by = 'date_start';
if (!in_array($sort_dir, ['ASC', 'DESC']))   $sort_dir = 'DESC';

// WHERE podmínky podle aktivního filtru
$_filterNav = buildFilterSQL($filterActive ? null : [], 't.');
$params_nav = $_filterNav['params'];
$whereNav   = $_filterNav['where'] ? ('WHERE ' . $_filterNav['where']) : '';

// Aktuální hodnota řazeného sloupce
$currentVal = $track[$sort_by] ?? null;

// ====== Navigace — 2 cílené queries místo fetchAll všech ID (PERF-2, BE-12) ======
//
// Řazení je ($sort_by $sort_dir, id ASC).
// "Předchozí" = záznam těsně před aktuálním v tomto řazení:
//   - při ASC: největší hodnota menší než $currentVal, nebo stejná hodnota s menším id
//   - při DESC: nejmenší hodnota větší než $currentVal, nebo stejná hodnota s menším id
//
// "Následující" = symetricky opačný direction.

$prevId    = null;
$nextId    = null;
$position  = null;
$total_nav = null; // není potřeba pro navigaci; zrušeno (bylo O(n))

if ($sort_dir === 'ASC') {
    // --- Previous: (col < cur) OR (col = cur AND id < cur_id), ORDER col DESC, id DESC
    $paramsPrev = $params_nav;
    $paramsPrev[':nav_cur_val']  = $currentVal;
    $paramsPrev[':nav_cur_val2'] = $currentVal;
    $paramsPrev[':nav_cur_id']   = $id;
    $filterAnd = $whereNav ? 'AND' : 'WHERE';
    $stmtPrev = $pdo->prepare("
        SELECT t.id FROM tracks t
        $whereNav
        $filterAnd (t.`$sort_by` < :nav_cur_val OR (t.`$sort_by` = :nav_cur_val2 AND t.id < :nav_cur_id))
        ORDER BY t.`$sort_by` DESC, t.id DESC
        LIMIT 1
    ");
    $stmtPrev->execute($paramsPrev);
    $prevId = $stmtPrev->fetchColumn() ?: null;

    // --- Next: (col > cur) OR (col = cur AND id > cur_id), ORDER col ASC, id ASC
    $paramsNext = $params_nav;
    $paramsNext[':nav_cur_val']  = $currentVal;
    $paramsNext[':nav_cur_val2'] = $currentVal;
    $paramsNext[':nav_cur_id']   = $id;
    $stmtNext = $pdo->prepare("
        SELECT t.id FROM tracks t
        $whereNav
        $filterAnd (t.`$sort_by` > :nav_cur_val OR (t.`$sort_by` = :nav_cur_val2 AND t.id > :nav_cur_id))
        ORDER BY t.`$sort_by` ASC, t.id ASC
        LIMIT 1
    ");
    $stmtNext->execute($paramsNext);
    $nextId = $stmtNext->fetchColumn() ?: null;

} else {
    // DESC primary sort, tie-break by id ASC
    // "Previous" in display order = higher value (or same value, smaller id)
    $paramsPrev = $params_nav;
    $paramsPrev[':nav_cur_val']  = $currentVal;
    $paramsPrev[':nav_cur_val2'] = $currentVal;
    $paramsPrev[':nav_cur_id']   = $id;
    $filterAnd = $whereNav ? 'AND' : 'WHERE';
    $stmtPrev = $pdo->prepare("
        SELECT t.id FROM tracks t
        $whereNav
        $filterAnd (t.`$sort_by` > :nav_cur_val OR (t.`$sort_by` = :nav_cur_val2 AND t.id < :nav_cur_id))
        ORDER BY t.`$sort_by` ASC, t.id DESC
        LIMIT 1
    ");
    $stmtPrev->execute($paramsPrev);
    $prevId = $stmtPrev->fetchColumn() ?: null;

    // "Next" in display order = lower value (or same value, larger id)
    $paramsNext = $params_nav;
    $paramsNext[':nav_cur_val']  = $currentVal;
    $paramsNext[':nav_cur_val2'] = $currentVal;
    $paramsNext[':nav_cur_id']   = $id;
    $stmtNext = $pdo->prepare("
        SELECT t.id FROM tracks t
        $whereNav
        $filterAnd (t.`$sort_by` < :nav_cur_val OR (t.`$sort_by` = :nav_cur_val2 AND t.id > :nav_cur_id))
        ORDER BY t.`$sort_by` DESC, t.id ASC
        LIMIT 1
    ");
    $stmtNext->execute($paramsNext);
    $nextId = $stmtNext->fetchColumn() ?: null;
}