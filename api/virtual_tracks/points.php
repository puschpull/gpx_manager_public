<?php
declare(strict_types=1);

/**
 * api/virtual_tracks/points.php — PUBLIC read-only: fotky virtuální trasy
 * GET ?id=<virtual_track_id>  (seřazené taken_at ASC = pořadí polyline)
 * csrf: no | admin: no
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';
require_once __DIR__ . '/../../includes/photo_helper.php';

ajax_endpoint(function () use ($pdo): array {
    $vtId = (int)($_GET['id'] ?? 0);
    if (!$vtId) {
        return ['photos' => []];
    }

    $stmt = $pdo->prepare("
        SELECT id, filename, orig_name, lat, lon, altitude, taken_at, caption
        FROM   track_photos
        WHERE  virtual_track_id = ?
          AND  lat IS NOT NULL
          AND  lon IS NOT NULL
          AND  (visible IS NULL OR visible = 1)
        ORDER  BY taken_at ASC, id ASC
    ");
    $stmt->execute([$vtId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $photos = array_map(fn($r) => [
        'id'        => (int)$r['id'],
        'thumb_url' => photo_thumb_url($r['filename']),
        'full_url'  => photo_full_url($r['filename']),
        'lat'       => (float)$r['lat'],
        'lon'       => (float)$r['lon'],
        'altitude'  => $r['altitude'] !== null ? round((float)$r['altitude']) : null,
        'taken_at'  => $r['taken_at'],
        'caption'   => $r['caption'],
        'filename'  => $r['orig_name'] ?: $r['filename'],
    ], $rows);

    return ['photos' => $photos];
}, ['csrf' => false, 'admin' => false, 'name' => 'virtual_tracks/points']);
