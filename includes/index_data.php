<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$allowedLangs = available_langs();

/* ===================== Konfigurace ===================== */
$DEFAULT_PER_PAGE = 50;

/* ===== Mapování label → DB sloupec pro řazení ===== */
$sortOptions = [
    'ID'                           => 'id',
    'Soubor'                       => 'filename',
    'Název trasy'                  => 'track_name',
    'Alt název'                    => 'alt_title',
    'Poznámka'                     => 'note',
    'Barva'                        => 'color',
    'Zařízení'                     => 'device',
    'Start'                        => 'date_start',
    'Konec'                        => 'date_end',
    'Doba (s)'                     => 'duration',
    'Pohyb (s)'                    => 'moving_time',
    'Stání (s)'                    => 'stopped_time',
    'Vzdálenost (km)'              => 'distance_km',
    'Stoupání (m)'                 => 'ascent',
    'Klesání (m)'                  => 'descent',
    'Min výška (m)'                => 'elevation_min',
    'Max výška (m)'                => 'elevation_max',
    'Max rychlost (km/h)'          => 'speed_max',
    'Prům. rychlost (km/h)'        => 'speed_avg',
    'Prům. rychl. celk. (km/h)'    => 'speed_avg_total',
    'avg↑ (m/s)'                   => 'avg_ascent_rate',
    'avg↓ (m/s)'                   => 'avg_descent_rate',
    'max↑ (m/s)'                   => 'max_ascent_rate',
    'max↓ (m/s)'                   => 'max_descent_rate',
    'Počet bodů'                   => 'trackpoints_count',
    'Obtížnost'                    => 'difficulty',
    'Aktivita'                     => 'activity_type',
    'Oblíbené'                     => 'is_favorite',
    'Vytvořeno'                    => 'created_at',
];

/* ===================== Vstupy z GET ===================== */
$q           = trim($_GET['q'] ?? '');
$color       = trim($_GET['color'] ?? '');

$cat_raw = $_GET['cat'] ?? [];
if (!is_array($cat_raw)) $cat_raw = $cat_raw !== '' ? [$cat_raw] : [];
$cat = array_values(array_filter(array_map('trim', $cat_raw)));

$date_from      = trim($_GET['date_from']      ?? '');
$date_to        = trim($_GET['date_to']        ?? '');
$dist_min       = trim($_GET['dist_min']       ?? '');
$dist_max       = trim($_GET['dist_max']       ?? '');
$ascent_min     = trim($_GET['ascent_min']     ?? '');
$ascent_max     = trim($_GET['ascent_max']     ?? '');
$descent_min    = trim($_GET['descent_min']    ?? '');
$descent_max    = trim($_GET['descent_max']    ?? '');
$savg_min       = trim($_GET['savg_min']       ?? '');
$savg_max       = trim($_GET['savg_max']       ?? '');
$savg_total_min = trim($_GET['savg_total_min'] ?? '');
$savg_total_max = trim($_GET['savg_total_max'] ?? '');
$smax_min       = trim($_GET['smax_min']       ?? '');
$smax_max       = trim($_GET['smax_max']       ?? '');

$fav_only       = trim($_GET['fav_only']       ?? '');
$diff_min       = trim($_GET['diff_min']       ?? '');
$diff_max       = trim($_GET['diff_max']       ?? '');

$elevation_min_val = trim($_GET['elevation_min_val'] ?? '');
$elevation_min_max = trim($_GET['elevation_min_max'] ?? '');
$elevation_max_val = trim($_GET['elevation_max_val'] ?? '');
$elevation_max_max = trim($_GET['elevation_max_max'] ?? '');

/* ===== Řazení ===== */
$sort_by  = $_GET['sort_by']  ?? '';
$sort_dir = strtoupper($_GET['sort_dir'] ?? '');

$sortable = array_values($sortOptions);
if (!in_array($sort_by, $sortable, true)) $sort_by = 'date_start';
$sort_dir = ($sort_dir === 'ASC') ? 'ASC' : 'DESC';

/* ===== Stránkování ===== */
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = (int)($_GET['per_page'] ?? $DEFAULT_PER_PAGE);
if (!in_array($per_page, [5,10,25,50,100,200,500], true)) $per_page = $DEFAULT_PER_PAGE;

/* ===== Timestampy pro zvýraznění rozsahu ===== */
$df_ts = $date_from !== '' ? strtotime($date_from) : null;
$dt_ts = $date_to   !== '' ? strtotime($date_to)   : null;
$rangeActive = ($df_ts !== null || $dt_ts !== null);

/* ===== Filtr aktivní po kliknutí na Filtrovat ===== */
$filterActive = isset($_GET['filter_submit']);

/* ===================== WHERE podmínky ===================== */
$_filterMain = buildFilterSQL($filterActive ? null : [], 't.');
$params_main = $_filterMain['params'];
$whereSql    = $_filterMain['where'] ? ('WHERE ' . $_filterMain['where']) : '';

/* ===================== Celkový počet ===================== */
$countSql  = "SELECT COUNT(*) FROM tracks t $whereSql";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params_main);
$total_rows = (int)$countStmt->fetchColumn();

$total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

/* ===================== Hlavní dotaz ===================== */
$sql = "SELECT t.*,
        (SELECT COUNT(*) FROM track_photos p WHERE p.track_id = t.id AND (p.visible IS NULL OR p.visible = 1)) AS photo_count
        FROM tracks t $whereSql ORDER BY t.`$sort_by` $sort_dir LIMIT :lim OFFSET :off";

$stmt = $pdo->prepare($sql);
foreach ($params_main as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset,   PDO::PARAM_INT);
$stmt->execute();
$tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===== Barvy a kategorie do selectů (s počty) ===== */
$colors = [];
$colorCounts = [];
try {
    $cstmt  = $pdo->query("SELECT color, COUNT(*) AS cnt FROM tracks WHERE color IS NOT NULL AND color <> '' GROUP BY color ORDER BY color");
    while ($row = $cstmt->fetch(PDO::FETCH_ASSOC)) {
        $colors[] = $row['color'];
        $colorCounts[$row['color']] = (int)$row['cnt'];
    }
} catch (Throwable $e) { error_log("index_data.php colors: " . $e->getMessage()); $colors = []; $colorCounts = []; }

$catCountStmt = $pdo->query("
    SELECT c.name, COUNT(tc.track_id) AS cnt
    FROM categories c
    LEFT JOIN track_categories tc ON tc.category_id = c.id
    GROUP BY c.id, c.name
    ORDER BY c.name
");
$allCats = [];
$catCounts = [];
while ($row = $catCountStmt->fetch(PDO::FETCH_ASSOC)) {
    $allCats[] = $row['name'];
    $catCounts[$row['name']] = (int)$row['cnt'];
}

/* ===== Kategorie pro zobrazené řádky ===== */
$catsByTrack = [];
if (!empty($tracks)) {
    $ids = array_column($tracks, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $qCats = $pdo->prepare("
        SELECT tc.track_id, c.name
        FROM track_categories tc
        JOIN categories c ON c.id = tc.category_id
        WHERE tc.track_id IN ($in)
        ORDER BY c.name
    ");
    $qCats->execute($ids);
    while ($row = $qCats->fetch(PDO::FETCH_ASSOC)) {
        $tid = (int)$row['track_id'];
        if (!isset($catsByTrack[$tid])) $catsByTrack[$tid] = [];
        $catsByTrack[$tid][] = $row['name'];
    }
}

/* ===== Souhrnné statistiky ===== */
$stats_sql = "
    SELECT
        COUNT(*)            AS total_tracks,
        SUM(t.distance_km)  AS total_km,
        SUM(t.ascent)       AS total_ascent,
        SUM(t.descent)      AS total_descent,
        AVG(t.speed_avg)    AS avg_speed
    FROM tracks t
";
$_filterStats = buildFilterSQL($filterActive ? null : [], 't.');
$params_stats = $_filterStats['params'];
$whereStats   = $_filterStats['where'];
if ($whereStats) $stats_sql .= " WHERE $whereStats";

$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute($params_stats);
$filtered_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
foreach ($filtered_stats as $k => $v) {
    $filtered_stats[$k] = $v ?? 0;
}

/* ===== Data pro graf ===== */
$chart_sql = "
    SELECT
        DATE_FORMAT(t.date_start, '%Y-%m') AS month,
        SUM(t.distance_km)                 AS total_distance,
        COUNT(*)                           AS total_tracks,
        SUM(t.ascent)                      AS total_ascent,
        AVG(t.speed_avg)                   AS avg_speed
    FROM tracks t
";
$_filterChart = buildFilterSQL($filterActive ? null : [], 't.');
$params_chart = $_filterChart['params'];
$whereChart   = $_filterChart['where'];
if ($whereChart) $chart_sql .= " WHERE $whereChart";
$chart_sql .= " GROUP BY DATE_FORMAT(t.date_start, '%Y-%m') ORDER BY month ASC";

$chart_stmt = $pdo->prepare($chart_sql);
$chart_stmt->execute($params_chart);
$chart_data = $chart_stmt->fetchAll(PDO::FETCH_ASSOC);

$chart_labels    = [];
$chart_distance  = [];
$chart_count     = [];
$chart_ascent    = [];
$chart_avg_speed = [];
foreach ($chart_data as $row) {
    $chart_labels[]    = $row['month'];
    $chart_distance[]  = round($row['total_distance'], 1);
    $chart_count[]     = (int)$row['total_tracks'];
    $chart_ascent[]    = round($row['total_ascent'], 0);
    $chart_avg_speed[] = round((float)($row['avg_speed'] ?? 0), 1);
}

/* ===== Celkové statistiky bez filtru ===== */
$totals_stmt = $pdo->query("
    SELECT
        COUNT(*)           AS total_tracks,
        SUM(distance_km)   AS total_km,
        SUM(ascent)        AS total_ascent,
        SUM(descent)       AS total_descent,
        AVG(speed_avg)     AS avg_speed
    FROM tracks
");
$stats = $totals_stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_tracks'  => 0,
    'total_km'      => 0,
    'total_ascent'  => 0,
    'total_descent' => 0,
    'avg_speed'     => 0
];

/* ===== AJAX výstup ===== */
if (isset($_GET['ajax'])) {
    ob_start();
    include __DIR__ . '/table_tracks.php';
    $table_html = ob_get_clean();
    echo json_encode([
        'table_html' => $table_html,
        'chart_data' => $chart_data ?? []
    ]);
    exit;
}
?>