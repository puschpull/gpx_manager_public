<?php
declare(strict_types=1);

/**
 * api/virtual_tracks/preview.php — návrh virtuálních tras (dry-run, NIC nezapisuje)
 * POST gap_hours, gap_km, min_photos
 * csrf: yes | admin: yes
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';
require_once __DIR__ . '/../../includes/virtual_track_helper.php';

ajax_endpoint(function () use ($pdo): array {
    $gapHours  = max(0.1, (float)($_POST['gap_hours'] ?? 4));
    $gapKm     = max(0.1, (float)($_POST['gap_km']    ?? 5));
    $minPhotos = max(2,   (int)($_POST['min_photos']  ?? 3));

    $photos = vt_fetch_candidates($pdo);
    $res    = vt_cluster_photos($photos, $gapHours, $gapKm, $minPhotos);

    $clusters = [];
    foreach ($res['clusters'] as $c) {
        $s = vt_compute_stats($c);
        $clusters[] = [
            'name'        => $s['name'],
            'date_start'  => $s['date_start'],
            'date_end'    => $s['date_end'],
            'photo_count' => $s['photo_count'],
            'distance_km' => $s['distance_km'],
            'ascent'      => $s['ascent'],
        ];
    }

    return [
        'ok'            => true,
        'candidates'    => count($photos),
        'cluster_count' => count($clusters),
        'rejected'      => count($res['rejected']),
        'clusters'      => $clusters,
        'params'        => ['gap_hours' => $gapHours, 'gap_km' => $gapKm, 'min_photos' => $minPhotos],
    ];
}, ['csrf' => true, 'admin' => true, 'name' => 'virtual_tracks/preview']);
