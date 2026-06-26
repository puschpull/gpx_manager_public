<?php
declare(strict_types=1);

/**
 * api/virtual_tracks/suggest_name.php — navrhne název VT podle míst na trase
 * (Mapy.com reverse-geocode). GET/POST id.
 *
 * Nic nezapisuje — vrací jen návrh; uložení jde přes rename.php.
 * csrf: no | admin: yes  (read-only, ale stojí API kvótu → jen admin)
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';
require_once __DIR__ . '/../../includes/virtual_track_helper.php';

ajax_endpoint(function () use ($pdo): array {
    if (!defined('MAPYCOM_API_KEY') || MAPYCOM_API_KEY === '') {
        return ['ok' => false, 'error' => 'Chybí Mapy.com API klíč.'];
    }

    $vtId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($vtId <= 0) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Chybí id'];
    }

    $meta = $pdo->prepare("SELECT date_start, photo_count FROM virtual_tracks WHERE id = ?");
    $meta->execute([$vtId]);
    $vt = $meta->fetch(\PDO::FETCH_ASSOC);
    if (!$vt) {
        http_response_code(404);
        return ['ok' => false, 'error' => 'Trasa nenalezena'];
    }

    $stmt = $pdo->prepare("
        SELECT lat, lon FROM track_photos
        WHERE virtual_track_id = ? AND lat IS NOT NULL AND lon IS NOT NULL
        ORDER BY taken_at ASC, id ASC
    ");
    $stmt->execute([$vtId]);
    $pts = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    if (count($pts) < 1) {
        return ['ok' => false, 'error' => 'Trasa nemá body s GPS.'];
    }

    $name = vt_suggest_name_from_points($pts, (string)$vt['date_start'], (int)$vt['photo_count']);
    return ['ok' => true, 'name' => $name];
}, ['csrf' => false, 'admin' => true, 'name' => 'virtual_tracks/suggest_name']);
