<?php
declare(strict_types=1);

/**
 * track_lines.php — simplified track lines with a per-track disk cache.
 *
 * Shared by api/tracks/lines.php (layer "Moje trasy" on the Map page),
 * the year summary, collections and the tile explorer. A line is simplified
 * with Douglas–Peucker to TRACK_LINES_SIMPLIFY metres and cached in
 * uploads/_line_cache/<id>.json; the cache is rebuilt when the GPX is newer.
 */

require_once __DIR__ . '/geojson_helper.php';

if (!defined('TRACK_LINES_SIMPLIFY')) {
    define('TRACK_LINES_SIMPLIFY', 10.0);   // metres
}

/**
 * Track segments as [[lat, lon], …] (MultiLineString → several segments), cached.
 *
 * @return array<int, array<int, array{0: float, 1: float}>>|null
 */
function track_lines_cached(int $id, string $filename): ?array
{
    try {
        $gpxPath = uploads_fs($filename);
        $dir     = uploads_fs('_line_cache');
    } catch (\RuntimeException $e) {
        return null;
    }
    $cache = $dir . DIRECTORY_SEPARATOR . $id . '.json';
    if (is_file($cache) && is_file($gpxPath) && filemtime($cache) >= filemtime($gpxPath)) {
        $data = json_decode((string)file_get_contents($cache), true);
        if (is_array($data)) {
            return $data;
        }
    }

    $err  = null;
    $geom = track_geojson_geometry($filename, false, 1, $err, true, TRACK_LINES_SIMPLIFY);
    if ($geom === null) {
        return null;
    }
    $segments = [];
    foreach ($geom['coordinates'] as $line) {
        $seg = [];
        foreach ($line as $p) {
            $seg[] = [round((float)$p[1], 5), round((float)$p[0], 5)];
        }
        if (count($seg) >= 2) {
            $segments[] = $seg;
        }
    }

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($cache, json_encode($segments), LOCK_EX);
    return $segments;
}

/**
 * Lines for a list of track rows (id, filename + display fields) for a Leaflet map.
 * Keeps the output small: coordinates rounded to 4 decimals (~10 m).
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function track_lines_for_rows(\PDO $pdo, array $rows): array
{
    require_once __DIR__ . '/track_title.php';
    $out = [];
    foreach ($rows as $r) {
        $segments = track_lines_cached((int)$r['id'], (string)$r['filename']);
        if (!$segments) {
            continue;
        }
        foreach ($segments as &$seg) {
            foreach ($seg as &$p) {
                $p = [round((float)$p[0], 4), round((float)$p[1], 4)];
            }
            unset($p);
        }
        unset($seg);
        $r['place_name'] = $r['place_name'] ?? '';
        $out[] = [
            'id'    => (int)$r['id'],
            'name'  => track_display_title($pdo, $r),
            'date'  => isset($r['date_start']) ? substr((string)$r['date_start'], 0, 10) : null,
            'km'    => round((float)($r['distance_km'] ?? 0), 1),
            'lines' => $segments,
        ];
    }
    return $out;
}
