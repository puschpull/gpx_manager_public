<?php
declare(strict_types=1);

/**
 * api/photos/nearby.php — PUBLIC read-only: nejbližší fotografie k bodu
 * GET ?lat=&lon=&radius_km=(5|10|25|50)&limit=(1..100)
 * csrf: no | admin: no — skryté fotky vidí jen admin (stejně jako bounds.php)
 *
 * Flow: SQL BBOX prefiltr na lat/lon (idx_tp_coords) s přibližným řazením,
 * pak přesný haversine v PHP + ořez na poloměr. Žádné parsování souborů.
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';
require_once __DIR__ . '/../../includes/gpx_parser.php'; // haversine()

ajax_endpoint(function () use ($pdo): array {
    $lat = (float)($_GET['lat'] ?? 0);
    $lon = (float)($_GET['lon'] ?? 0);
    if ($lat == 0.0 && $lon == 0.0) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Chybí souřadnice bodu.'];
    }

    $radiusKm = (float)($_GET['radius_km'] ?? 10);
    if (!in_array($radiusKm, [5.0, 10.0, 25.0, 50.0], true)) {
        $radiusKm = 10.0;
    }
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));

    $latDelta = $radiusKm / 111.0;
    $lonDelta = $radiusKm / (111.0 * max(cos(deg2rad($lat)), 0.01));
    $cosLat   = cos(deg2rad($lat));

    // Skryté fotky vidí jen admin (stejný filtr jako photos_data.php)
    $visibleFilter = empty($_SESSION['is_admin'])
        ? 'AND (p.visible IS NULL OR p.visible = 1)'
        : '';

    // Přibližné řazení v SQL PŘED LIMITem (jinak by blízké fotky vypadly),
    // přesná vzdálenost se dopočítá haversinem níže.
    $stmt = $pdo->prepare("
        SELECT p.id, p.filename, p.orig_name, p.lat, p.lon, p.altitude,
               p.taken_at, p.caption, p.track_id, p.virtual_track_id,
               t.track_name, vt.name AS virtual_track_name
        FROM track_photos p
        LEFT JOIN tracks t          ON t.id  = p.track_id
        LEFT JOIN virtual_tracks vt ON vt.id = p.virtual_track_id
        WHERE p.lat BETWEEN :latMin AND :latMax
          AND p.lon BETWEEN :lonMin AND :lonMax
          $visibleFilter
        ORDER BY (POW(p.lat - :cLat, 2) + POW((p.lon - :cLon) * :cosLat, 2)) ASC
        LIMIT :lim
    ");
    $stmt->bindValue(':latMin', $lat - $latDelta);
    $stmt->bindValue(':latMax', $lat + $latDelta);
    $stmt->bindValue(':lonMin', $lon - $lonDelta);
    $stmt->bindValue(':lonMax', $lon + $lonDelta);
    $stmt->bindValue(':cLat',   $lat);
    $stmt->bindValue(':cLon',   $lon);
    $stmt->bindValue(':cosLat', $cosLat);
    $stmt->bindValue(':lim',    $limit, \PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $photos = [];
    foreach ($rows as $r) {
        $distM = haversine($lat, $lon, (float)$r['lat'], (float)$r['lon']);
        if ($distM > $radiusKm * 1000) continue; // BBOX roh mimo kruhový poloměr

        $photos[] = [
            'id'                 => (int)$r['id'],
            'thumb_url'          => photo_thumb_url($r['filename']),
            'full_url'           => photo_full_url($r['filename']),
            'lat'                => (float)$r['lat'],
            'lon'                => (float)$r['lon'],
            'altitude'           => $r['altitude'] !== null ? round((float)$r['altitude']) : null,
            'taken_at'           => $r['taken_at'],
            'caption'            => $r['caption'],
            'filename'           => $r['orig_name'] ?: $r['filename'],
            'distance_m'         => (int)round($distM),
            'track_id'           => $r['track_id'] !== null ? (int)$r['track_id'] : null,
            'virtual_track_id'   => $r['virtual_track_id'] !== null ? (int)$r['virtual_track_id'] : null,
            'track_name'         => $r['track_name'],
            'virtual_track_name' => $r['virtual_track_name'],
        ];
    }

    // Přesné seřazení podle haversine (SQL řadilo jen přibližně)
    usort($photos, static fn($a, $b) => $a['distance_m'] <=> $b['distance_m']);

    return [
        'photos'    => $photos,
        'radius_km' => $radiusKm,
        'limit'     => $limit,
    ];
}, ['csrf' => false, 'admin' => false, 'name' => 'photos/nearby']);
