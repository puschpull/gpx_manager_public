<?php
declare(strict_types=1);

/**
 * api/virtual_tracks/suggest_names.php — návrhy názvů dle míst pro shluky generátoru.
 * POST gap_hours, gap_km, min_photos.
 *
 * Re-shlukne (stejně jako preview.php se stejnými parametry) a pro každý shluk
 * vrátí návrh názvu dle míst (Mapy.com), v pořadí shluků. Nic nezapisuje.
 * csrf: yes | admin: yes
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';
require_once __DIR__ . '/../../includes/virtual_track_helper.php';

ajax_endpoint(function () use ($pdo): array {
    if (!defined('MAPYCOM_API_KEY') || MAPYCOM_API_KEY === '') {
        return ['ok' => false, 'error' => 'Chybí Mapy.com API klíč.'];
    }

    $gapHours  = max(0.1, (float)($_POST['gap_hours'] ?? 4));
    $gapKm     = max(0.1, (float)($_POST['gap_km']    ?? 5));
    $minPhotos = max(2,   (int)($_POST['min_photos']  ?? 3));

    $photos = vt_fetch_candidates($pdo);
    $res    = vt_cluster_photos($photos, $gapHours, $gapKm, $minPhotos);

    $names = [];
    foreach ($res['clusters'] as $c) {
        $s       = vt_compute_stats($c);
        $names[] = vt_suggest_name_from_points($c, (string)$s['date_start'], (int)$s['photo_count']);
    }

    return ['ok' => true, 'names' => $names, 'cluster_count' => count($names)];
}, ['csrf' => true, 'admin' => true, 'name' => 'virtual_tracks/suggest_names']);
