<?php
declare(strict_types=1);

/**
 * api/virtual_tracks/lines.php — PUBLIC read-only: virtual tracks in a map view
 * GET ?minlat=&maxlat=&minlon=&maxlon=
 * csrf: no | admin: no — visitors only when the page 'virtual_tracks' is visible.
 *
 * For the layer "Virtuální trasy" on the Map page. A virtual track is chosen
 * when its bounding box overlaps the view; its line runs through the GPS
 * positions of its (visible) photos in time order, like on its detail.
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';

ajax_endpoint(function () use ($pdo): array {
    if (empty($_SESSION['is_admin'])
        && !in_array('virtual_tracks', (array)get_app_config('visible_pages', all_pages()), true)) {
        http_response_code(403);
        return ['ok' => false, 'error' => 'Forbidden'];
    }

    $num = static function (string $k, float $lim): ?float {
        $v = $_GET[$k] ?? null;
        if (!is_string($v) || !is_numeric($v)) {
            return null;
        }
        $f = (float)$v;
        return abs($f) <= $lim ? $f : null;
    };
    $minLat = $num('minlat', 90);
    $maxLat = $num('maxlat', 90);
    $minLon = $num('minlon', 180);
    $maxLon = $num('maxlon', 180);
    if ($minLat === null || $maxLat === null || $minLon === null || $maxLon === null
        || $minLat >= $maxLat || $minLon >= $maxLon) {
        http_response_code(400);
        return ['ok' => false, 'error' => t('api_bad_param', 'Invalid parameter')];
    }
    if ($maxLat - $minLat > 3.0 || $maxLon - $minLon > 5.0) {
        return ['ok' => true, 'tooBig' => true, 'tracks' => []];
    }

    $rows = $pdo->query("SELECT id, name, date_start, photo_count, distance_km, bounds FROM virtual_tracks
                         WHERE bounds IS NOT NULL")->fetchAll(\PDO::FETCH_ASSOC);
    $pts = $pdo->prepare("
        SELECT lat, lon FROM track_photos
        WHERE virtual_track_id = ? AND lat IS NOT NULL AND lon IS NOT NULL
          AND (visible IS NULL OR visible = 1)
        ORDER BY taken_at ASC, id ASC
    ");

    $out = [];
    foreach ($rows as $r) {
        $b = json_decode((string)$r['bounds'], true);
        if (!is_array($b) || !isset($b['minlat'], $b['maxlat'], $b['minlon'], $b['maxlon'])) {
            continue;
        }
        if ($b['maxlat'] < $minLat || $b['minlat'] > $maxLat || $b['maxlon'] < $minLon || $b['minlon'] > $maxLon) {
            continue;
        }
        $pts->execute([(int)$r['id']]);
        $line = array_map(static fn($p) => [round((float)$p['lat'], 5), round((float)$p['lon'], 5)],
                          $pts->fetchAll(\PDO::FETCH_ASSOC));
        if (count($line) < 2) {
            continue;
        }
        $out[] = [
            'id'     => (int)$r['id'],
            'name'   => (string)($r['name'] ?: str_replace('{id}', (string)$r['id'], t('vt_default_name'))),
            'date'   => $r['date_start'] !== null ? substr((string)$r['date_start'], 0, 10) : null,
            'photos' => (int)$r['photo_count'],
            'km'     => $r['distance_km'] !== null ? round((float)$r['distance_km'], 1) : null,
            'line'   => $line,
        ];
    }
    return ['ok' => true, 'tracks' => $out];
}, ['csrf' => false, 'admin' => false, 'name' => 'virtual_tracks/lines']);
