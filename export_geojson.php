<?php
declare(strict_types=1);

/**
 * export_geojson.php — Export trasy jako GeoJSON (QGIS, Leaflet, Mapbox…)
 */
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/geojson_helper.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit('Invalid ID'); }

$stmt = $pdo->prepare("
    SELECT id, filename, track_name, distance_km, ascent, descent,
           elevation_min, elevation_max, duration, date_start, activity_type, difficulty
    FROM tracks WHERE id = ?
");
$stmt->execute([$id]);
$track = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$track) { http_response_code(404); exit('Track not found'); }

// Segments → MultiLineString; elevation keeps 2 decimals as before
$feature = track_geojson_feature($track, true, 2, $error);
if ($feature === null) {
    if ($error === 'parse_error' || $error === 'no_points') {
        http_response_code(422); exit('Failed to parse GPX');
    }
    http_response_code(404); exit('GPX file not found');
}

$name     = $feature['properties']['name'];
$safeName = preg_replace('/[^\w\-]/', '_', $name);

header('Content-Type: application/geo+json; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $safeName . '.geojson"');

$geojson = [
    'type'     => 'FeatureCollection',
    'features' => [$feature],
];

echo json_encode($geojson, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
