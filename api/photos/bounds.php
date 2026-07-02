<?php
declare(strict_types=1);

/**
 * api/photos/bounds.php — PUBLIC read-only: photos within a lat/lon bounding box
 * GET ?minlat=&maxlat=&minlon=&maxlon=
 * csrf: no | admin: no  (public read-only endpoint)
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';
require_once __DIR__ . '/../../includes/photo_helper.php';

ajax_endpoint(function () use ($pdo): array {
    $minLat = (float)($_GET['minlat'] ?? 0);
    $maxLat = (float)($_GET['maxlat'] ?? 0);
    $minLon = (float)($_GET['minlon'] ?? 0);
    $maxLon = (float)($_GET['maxlon'] ?? 0);

    if ($minLat == 0 && $maxLat == 0) {
        return ['photos' => []];
    }

    // Skryté fotky vidí jen admin (stejný filtr jako photos_data.php)
    $visibleFilter = empty($_SESSION['is_admin'])
        ? 'AND (visible IS NULL OR visible = 1)'
        : '';

    $stmt = $pdo->prepare("
        SELECT id, filename, lat, lon, altitude, taken_at
        FROM   track_photos
        WHERE  lat BETWEEN :minlat AND :maxlat
          AND  lon BETWEEN :minlon AND :maxlon
          $visibleFilter
        ORDER  BY taken_at ASC
        LIMIT  200
    ");
    $stmt->execute([
        ':minlat' => $minLat, ':maxlat' => $maxLat,
        ':minlon' => $minLon, ':maxlon' => $maxLon,
    ]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $photos = array_map(fn($r) => [
        'id'        => (int)$r['id'],
        'thumb_url' => photo_thumb_url($r['filename']),
        'full_url'  => photo_full_url($r['filename']),
        'lat'       => (float)$r['lat'],
        'lon'       => (float)$r['lon'],
        'taken_at'  => $r['taken_at'],
    ], $rows);

    return ['photos' => $photos];
}, ['csrf' => false, 'admin' => false, 'name' => 'photos/bounds']);
