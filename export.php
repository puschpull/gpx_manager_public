<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

ob_clean();
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="tracks_export_' . date('Y-m-d_His') . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF"; // UTF-8 BOM pro Excel

$filterActive = isset($_GET['filter_submit']);

$params  = [];
$whereSql = buildFilterSQL($filterActive, $params, 't.');
$whereSql = $whereSql ? ('WHERE ' . $whereSql) : '';

$sql = "
    SELECT
        t.id, t.filename, t.track_name, t.alt_title,
        t.color, t.device,
        t.date_start, t.date_end,
        t.duration, t.moving_time, t.stopped_time,
        t.distance_km, t.ascent, t.descent,
        t.elevation_min, t.elevation_max,
        t.speed_max, t.speed_avg, t.speed_avg_total,
        t.avg_ascent_rate, t.avg_descent_rate,
        t.max_ascent_rate, t.max_descent_rate,
        t.created_at
    FROM tracks t
    $whereSql
    ORDER BY t.date_start DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===== Kategorie ===== */
if (!empty($rows)) {
    $ids = array_column($rows, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));

    $catStmt = $pdo->prepare("
        SELECT tc.track_id, GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') AS categories
        FROM track_categories tc
        JOIN categories c ON c.id = tc.category_id
        WHERE tc.track_id IN ($in)
        GROUP BY tc.track_id
    ");
    $catStmt->execute($ids);
    $catMap = [];
    while ($r = $catStmt->fetch(PDO::FETCH_ASSOC)) {
        $catMap[(int)$r['track_id']] = $r['categories'];
    }

    foreach ($rows as &$row) {
        $row['categories'] = $catMap[(int)$row['id']] ?? '';
    }
    unset($row);
}

/* ===== Export ===== */
$out = fopen('php://output', 'w');

fputcsv($out, [
    'ID', 'Soubor', 'Název trasy', 'Alt název', 'Kategorie', 'Barva', 'Zařízení',
    'Start', 'Konec', 'Doba (s)', 'Pohyb (s)', 'Stání (s)',
    'Vzdálenost (km)', 'Stoupání (m)', 'Klesání (m)',
    'Min výška (m)', 'Max výška (m)',
    'Max rychlost (km/h)', 'Prům. rychlost (km/h)', 'Prům. rychl. celk. (km/h)',
    'avg↑ (m/s)', 'avg↓ (m/s)', 'max↑ (m/s)', 'max↓ (m/s)',
    'Vytvořeno'
], ';', '"', '\\');

foreach ($rows as $r) {
    fputcsv($out, [
        $r['id'],
        $r['filename'],
        $r['track_name'],
        $r['alt_title'],
        $r['categories'] ?? '',
        $r['color'],
        $r['device'],
        $r['date_start'],
        $r['date_end'],
        $r['duration'],
        $r['moving_time'],
        $r['stopped_time'],
        $r['distance_km'],
        $r['ascent'],
        $r['descent'],
        $r['elevation_min'],
        $r['elevation_max'],
        $r['speed_max'],
        $r['speed_avg'],
        $r['speed_avg_total'],
        $r['avg_ascent_rate'],
        $r['avg_descent_rate'],
        $r['max_ascent_rate'],
        $r['max_descent_rate'],
        $r['created_at'],
    ], ';', '"', '\\');
}

fclose($out);
exit;