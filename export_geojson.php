<?php
/**
 * export_geojson.php — Export trasy jako GeoJSON (QGIS, Leaflet, Mapbox…)
 */
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/gpx_parser.php';

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

$gpxPath = __DIR__ . '/uploads/' . $track['filename'];
if (!file_exists($gpxPath)) { http_response_code(404); exit('GPX file not found'); }

$gpx = safe_load_gpx($gpxPath);
if (!$gpx) { http_response_code(422); exit('Failed to parse GPX'); }

// Načtení trackpointů
$ns = $gpx->getNamespaces(true);
$defaultNs = $ns[''] ?? null;
if ($defaultNs) {
    $gpx->registerXPathNamespace('g', $defaultNs);
    $trkpts = $gpx->xpath('//g:trkpt') ?: [];
} else {
    $trkpts = $gpx->xpath('//trkpt') ?: [];
}

$name     = $track['track_name'] ?: pathinfo($track['filename'], PATHINFO_FILENAME);
$safeName = preg_replace('/[^\w\-]/', '_', $name);

header('Content-Type: application/geo+json; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $safeName . '.geojson"');

$coordinates = [];
foreach ($trkpts as $pt) {
    $lon  = round((float)$pt['lon'], 7);
    $lat  = round((float)$pt['lat'], 7);
    $ele  = isset($pt->ele) ? round((float)$pt->ele, 2) : 0;
    $coordinates[] = [$lon, $lat, $ele];
}

$geojson = [
    'type' => 'FeatureCollection',
    'features' => [[
        'type' => 'Feature',
        'properties' => [
            'name'          => $name,
            'distance_km'   => $track['distance_km']   !== null ? round((float)$track['distance_km'], 3)  : null,
            'ascent_m'      => $track['ascent']         !== null ? (int)$track['ascent']    : null,
            'descent_m'     => $track['descent']        !== null ? (int)$track['descent']   : null,
            'ele_min_m'     => $track['elevation_min']  !== null ? (int)$track['elevation_min']  : null,
            'ele_max_m'     => $track['elevation_max']  !== null ? (int)$track['elevation_max']  : null,
            'duration_s'    => $track['duration']       !== null ? (int)$track['duration']  : null,
            'date'          => $track['date_start'] ? substr($track['date_start'], 0, 10) : null,
            'activity'      => $track['activity_type']  ?? null,
            'difficulty'    => $track['difficulty']     !== null ? (int)$track['difficulty'] : null,
        ],
        'geometry' => [
            'type'        => 'LineString',
            'coordinates' => $coordinates,
        ],
    ]],
];

echo json_encode($geojson, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
