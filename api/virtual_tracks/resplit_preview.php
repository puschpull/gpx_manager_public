<?php
declare(strict_types=1);

/**
 * api/virtual_tracks/resplit_preview.php — náhled rozdělení EXISTUJÍCÍ virtuální
 * trasy na úseky (dry-run, NIC nezapisuje).
 * POST id, gap_hours, gap_km, min_photos
 * Vrací úseky vč. seznamu photo_ids (pro obarvení na mapě).
 * csrf: yes | admin: yes
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';
require_once __DIR__ . '/../../includes/virtual_track_helper.php';

ajax_endpoint(function () use ($pdo): array {
    $id        = (int)($_POST['id'] ?? 0);
    $gapHours  = max(0.1, (float)($_POST['gap_hours'] ?? 4));
    $gapKm     = max(0.1, (float)($_POST['gap_km']    ?? 5));
    $minPhotos = max(2,   (int)($_POST['min_photos']  ?? 3));
    if ($id <= 0) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Chybí id'];
    }

    $photos = vt_fetch_track_photos($pdo, $id);
    if (count($photos) < 2) {
        return [
            'ok'            => true,
            'photo_total'   => count($photos),
            'segment_count' => count($photos) ? 1 : 0,
            'segments'      => [],
        ];
    }

    $segments = vt_split_photos($photos, $gapHours, $gapKm, $minPhotos);

    $out = [];
    foreach ($segments as $seg) {
        $s = vt_compute_stats($seg);
        $out[] = [
            'name'        => $s['name'],
            'date_start'  => $s['date_start'],
            'date_end'    => $s['date_end'],
            'photo_count' => $s['photo_count'],
            'distance_km' => $s['distance_km'],
            'ascent'      => $s['ascent'],
            'photo_ids'   => array_map(static fn($p) => (int)$p['id'], $seg),
        ];
    }

    return [
        'ok'            => true,
        'photo_total'   => count($photos),
        'segment_count' => count($out),
        'segments'      => $out,
        'params'        => ['gap_hours' => $gapHours, 'gap_km' => $gapKm, 'min_photos' => $minPhotos],
    ];
}, ['csrf' => true, 'admin' => true, 'name' => 'virtual_tracks/resplit_preview']);
