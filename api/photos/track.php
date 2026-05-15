<?php
declare(strict_types=1);

/**
 * api/photos/track.php — PUBLIC read-only: photos for a given track_id
 * GET ?id=<track_id>
 * csrf: no | admin: no  (public read-only endpoint)
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';
require_once __DIR__ . '/../../includes/photo_helper.php';

ajax_endpoint(function () use ($pdo): array {
    $trackId = (int)($_GET['id'] ?? 0);
    if (!$trackId) {
        return ['photos' => []];
    }

    $stmt = $pdo->prepare("
        SELECT id, filename, lat, lon, altitude, taken_at, width, height, caption, img_direction
        FROM   track_photos
        WHERE  track_id = ?
          AND  lat IS NOT NULL
          AND  lon IS NOT NULL
          AND  (visible IS NULL OR visible = 1)
        ORDER  BY taken_at ASC
    ");
    $stmt->execute([$trackId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $photos = array_map(fn($r) => [
        'id'            => (int)$r['id'],
        'thumb_url'     => photo_thumb_url($r['filename']),
        'full_url'      => photo_full_url($r['filename']),
        'lat'           => (float)$r['lat'],
        'lon'           => (float)$r['lon'],
        'altitude'      => $r['altitude'] !== null ? round((float)$r['altitude']) : null,
        'taken_at'      => $r['taken_at'],
        'width'         => $r['width'] ? (int)$r['width'] : null,
        'height'        => $r['height'] ? (int)$r['height'] : null,
        'caption'       => $r['caption'],
        'img_direction' => $r['img_direction'] !== null ? (float)$r['img_direction'] : null,
    ], $rows);

    return ['photos' => $photos];
}, ['csrf' => false, 'admin' => false, 'name' => 'photos/track']);
