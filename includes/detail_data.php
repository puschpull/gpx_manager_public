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
    $stmt = $pdo->prepare('SELECT id, bounds, distance_km, ascent, difficulty FROM tracks WHERE id = ?');
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

    // Střed bounding boxu aktuální trasy
    $curCenterLat = $curCenterLon = null;
    if ($curBounds && isset($curBounds['minlat'], $curBounds['maxlat'], $curBounds['minlon'], $curBounds['maxlon'])) {
        $curCenterLat = ($curBounds['minlat'] + $curBounds['maxlat']) / 2;
        $curCenterLon = ($curBounds['minlon'] + $curBounds['maxlon']) / 2;
    }

    // Načtení všech ostatních tras
    $allStmt = $pdo->query("
        SELECT id, filename, track_name, distance_km, ascent, descent,
               date_start, duration, bounds, color, difficulty, activity_type
        FROM tracks
        WHERE id != $trackId AND bounds IS NOT NULL AND bounds != ''
    ");
    $allTracks = $allStmt->fetchAll(PDO::FETCH_ASSOC);

    // Skóre podobnosti pro každou trasu
    $scored = [];
    foreach ($allTracks as $t) {
        $b = json_decode($t['bounds'], true);
        if (!$b || !isset($b['minlat'], $b['maxlat'], $b['minlon'], $b['maxlon'])) continue;

        $score = 0;

        // 1. Geografická blízkost (střed vs. střed) — max 50 bodů
        if ($curCenterLat !== null) {
            $tCenterLat = ($b['minlat'] + $b['maxlat']) / 2;
            $tCenterLon = ($b['minlon'] + $b['maxlon']) / 2;
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

// ====== Stylování ======
$allThemes = ['classic','dark','darkblue','darkgreen','blue','green','minimal','lightgray','brown'];
$available_themes = get_app_config('allowed_themes', $allThemes);
$theme = $_GET['theme'] ?? ($_COOKIE['theme'] ?? 'classic');
if (!in_array($theme, $available_themes)) $theme = $available_themes[0] ?? 'classic';
$allowedLangs = get_app_config('allowed_langs', ['cs','en','de','sk','es','fr','pl','it']);
if (isset($_GET['theme'])) setcookie('theme', $theme, time() + 365 * 24 * 3600, '/');

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
$params_nav = [];
$whereNav   = buildFilterSQL($filterActive, $params_nav, 't.');
$whereNav   = $whereNav ? ('WHERE ' . $whereNav) : '';

// Obrácený směr pro předchozí
$sort_dir_rev = ($sort_dir === 'ASC') ? 'DESC' : 'ASC';

// Aktuální hodnota řazeného sloupce
$currentVal = $track[$sort_by] ?? null;

// ====== Následující záznam ======
// Záznam který by byl "za" aktuálním v daném řazení
$sqlNext = "
    SELECT t.id FROM tracks t
    $whereNav
    AND (
        t.`$sort_by` $sort_dir 
            CASE WHEN '$sort_dir' = 'ASC' THEN > ELSE < END
        :cur_val
        OR (t.`$sort_by` = :cur_val2 AND t.id > :cur_id)
    )
    ORDER BY t.`$sort_by` $sort_dir, t.id ASC
    LIMIT 1
";

// Jednodušší přístup — načteme celý seznam ID v daném pořadí a najdeme pozici
$sqlAll = "
    SELECT t.id FROM tracks t
    $whereNav
    ORDER BY t.`$sort_by` $sort_dir, t.id ASC
";

$stmtAll = $pdo->prepare($sqlAll);
$stmtAll->execute($params_nav);
$allIds = $stmtAll->fetchAll(PDO::FETCH_COLUMN);

$prevId   = null;
$nextId   = null;
$position = null;
$total_nav = count($allIds);

$currentPos = array_search($id, $allIds);
if ($currentPos !== false) {
    $position = $currentPos + 1;
    $prevId   = ($currentPos > 0)              ? $allIds[$currentPos - 1] : null;
    $nextId   = ($currentPos < $total_nav - 1) ? $allIds[$currentPos + 1] : null;
}