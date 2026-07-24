<?php
declare(strict_types=1);

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

$_filterExport = buildFilterSQL($filterActive ? null : [], 't.');
$params        = $_filterExport['params'];
$whereSql      = $_filterExport['where'] ? ('WHERE ' . $_filterExport['where']) : '';

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

/* ===== CSV injection protection ===== */
/**
 * Prefix any cell value that starts with a formula-triggering character.
 * Excel/LibreOffice treat =, +, -, @, tab, carriage-return as formula prefixes.
 */
function csv_safe(?string $v): ?string {
    if ($v === null || $v === '') return $v;
    if (preg_match('/^[=+\-@\t\r]/', $v)) return "'" . $v;
    return $v;
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
        $r['id'],                            // numeric — safe
        csv_safe($r['filename']),
        csv_safe($r['track_name']),
        csv_safe($r['alt_title']),
        csv_safe($r['categories'] ?? ''),
        csv_safe($r['color']),
        csv_safe($r['device']),
        $r['date_start'],                    // date — safe
        $r['date_end'],                      // date — safe
        $r['duration'],                      // numeric — safe
        $r['moving_time'],                   // numeric — safe
        $r['stopped_time'],                  // numeric — safe
        $r['distance_km'],                   // numeric — safe
        $r['ascent'],                        // numeric — safe
        $r['descent'],                       // numeric — safe
        $r['elevation_min'],                 // numeric — safe
        $r['elevation_max'],                 // numeric — safe
        $r['speed_max'],                     // numeric — safe
        $r['speed_avg'],                     // numeric — safe
        $r['speed_avg_total'],               // numeric — safe
        $r['avg_ascent_rate'],               // numeric — safe
        $r['avg_descent_rate'],              // numeric — safe
        $r['max_ascent_rate'],               // numeric — safe
        $r['max_descent_rate'],              // numeric — safe
        $r['created_at'],                    // timestamp — safe
    ], ';', '"', '\\');
}

fclose($out);
exit;