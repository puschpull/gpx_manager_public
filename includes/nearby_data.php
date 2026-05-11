<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/gpx_parser.php';

// ====== Stylování ======
$allThemes = ['classic','dark','darkblue','darkgreen','blue','green','minimal','lightgray','brown'];
$available_themes = get_app_config('allowed_themes', $allThemes);
$theme = $_GET['theme'] ?? ($_COOKIE['theme'] ?? 'classic');
if (!in_array($theme, $available_themes)) $theme = $available_themes[0] ?? 'classic';
$allowedLangs = get_app_config('allowed_langs', ['cs','en','de','sk','es','fr','pl','it']);
if (isset($_GET['theme'])) setcookie('theme', $theme, time() + 365 * 24 * 3600, '/');

// ====== AJAX endpoint ======
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json; charset=utf-8');

    $clickLat = (float)($_GET['lat'] ?? 0);
    $clickLon = (float)($_GET['lon'] ?? 0);

    if ($clickLat == 0 && $clickLon == 0) {
        echo json_encode(['error' => 'Chybí souřadnice bodu.']);
        exit;
    }

    // Fáze 1: Načíst všechny trasy s bounds a spočítat dolní mez vzdálenosti
    $stmt = $pdo->query("
        SELECT id, filename, track_name, distance_km, ascent, descent,
               date_start, duration, bounds, color
        FROM tracks
        WHERE bounds IS NOT NULL AND bounds != ''
    ");
    $allTracks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $candidates = [];
    foreach ($allTracks as $t) {
        $b = json_decode($t['bounds'], true);
        if (!$b || !isset($b['minlat'], $b['maxlat'], $b['minlon'], $b['maxlon'])) continue;

        // Minimální vzdálenost kliknutého bodu k bounding boxu
        $nearestLat = max($b['minlat'], min($clickLat, $b['maxlat']));
        $nearestLon = max($b['minlon'], min($clickLon, $b['maxlon']));
        $minDist = haversine($clickLat, $clickLon, $nearestLat, $nearestLon);

        $t['bbox_dist'] = $minDist;
        $candidates[] = $t;
    }

    // Seřadit podle dolní meze vzdálenosti k bounding boxu
    usort($candidates, fn($a, $b) => $a['bbox_dist'] <=> $b['bbox_dist']);

    // Vzít prvních 30 kandidátů pro přesný výpočet
    $candidates = array_slice($candidates, 0, 30);

    // Fáze 2: Pro kandidáty naparsovat GPX a najít nejbližší trackpoint
    $results = [];
    foreach ($candidates as $t) {
        $gpxFile = uploads_fs($t['filename']);
        if (!is_file($gpxFile)) continue;

        $xml = @simplexml_load_file($gpxFile);
        if (!$xml) continue;

        $minDist = PHP_FLOAT_MAX;
        $nearestLat = null;
        $nearestLon = null;

        foreach ($xml->trk as $trk) {
            foreach ($trk->trkseg as $seg) {
                foreach ($seg->trkpt as $pt) {
                    $ptLat = (float)$pt['lat'];
                    $ptLon = (float)$pt['lon'];
                    $dist = haversine($clickLat, $clickLon, $ptLat, $ptLon);
                    if ($dist < $minDist) {
                        $minDist = $dist;
                        $nearestLat = $ptLat;
                        $nearestLon = $ptLon;
                    }
                }
            }
        }

        if ($nearestLat !== null) {
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
                'nearest_lat'         => round($nearestLat, 6),
                'nearest_lon'         => round($nearestLon, 6),
                'distance_from_point' => round($minDist / 1000, 2), // metry → km
            ];
        }
    }

    // Seřadit podle skutečné minimální vzdálenosti
    usort($results, fn($a, $b) => $a['distance_from_point'] <=> $b['distance_from_point']);

    // Vzít prvních 8
    $results = array_slice($results, 0, 8);

    echo json_encode(['tracks' => $results], JSON_UNESCAPED_UNICODE);
    exit;
}
