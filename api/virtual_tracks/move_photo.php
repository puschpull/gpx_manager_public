<?php
declare(strict_types=1);

/**
 * api/virtual_tracks/move_photo.php — posun fotky na mapě (oprava GPS polohy)
 * POST photo_id, lat, lon
 * Uloží novou polohu fotky a přepočítá statistiky její virtuální trasy.
 * csrf: yes | admin: yes
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';
require_once __DIR__ . '/../../includes/virtual_track_helper.php';

ajax_endpoint(function () use ($pdo): array {
    $photoId = (int)($_POST['photo_id'] ?? 0);
    $lat     = isset($_POST['lat']) ? (float)$_POST['lat'] : null;
    $lon     = isset($_POST['lon']) ? (float)$_POST['lon'] : null;

    if (!$photoId || $lat === null || $lon === null
        || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Neplatné parametry'];
    }

    // Ověřit, že fotka patří virtuální trase (jinak posun nepovolíme)
    $stmt = $pdo->prepare("SELECT virtual_track_id FROM track_photos WHERE id = ? LIMIT 1");
    $stmt->execute([$photoId]);
    $vtId = $stmt->fetchColumn();
    if (!$vtId) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Fotka nepatří žádné virtuální trase'];
    }
    $vtId = (int)$vtId;

    $pdo->prepare("UPDATE track_photos SET lat = ?, lon = ? WHERE id = ?")
        ->execute([$lat, $lon, $photoId]);

    // Přepočítat statistiky virtuální trasy (vzdálenost, bounds, centroid…)
    $s = vt_recompute_and_save($pdo, $vtId);

    return [
        'ok'          => true,
        'distance_km' => $s['distance_km'] ?? null,
        'ascent'      => $s['ascent'] ?? null,
        'descent'     => $s['descent'] ?? null,
    ];
}, ['csrf' => true, 'admin' => true, 'name' => 'virtual_tracks/move_photo']);
