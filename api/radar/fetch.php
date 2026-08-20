<?php
declare(strict_types=1);

/**
 * api/radar/fetch.php — stáhne skutečné radarové snímky ČHMÚ pro dobu trvání trasy
 * POST track_id | csrf: yes | admin: yes
 *
 * Vlastní stahování dělá radar_fetch_for_track() v includes/radar_helper.php —
 * stejnou funkci volá i import, aby se u čerstvé trasy dal radar stáhnout rovnou.
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';
require_once __DIR__ . '/../../includes/radar_helper.php';

ajax_endpoint(function () use ($pdo): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['ok' => false, 'error' => 'Method not allowed'];
    }
    $trackId = (int)($_POST['track_id'] ?? 0);
    if ($trackId <= 0) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Chybí track_id.'];
    }

    $res = radar_fetch_for_track($pdo, $trackId);
    if (!($res['ok'] ?? false)) {
        http_response_code(404);
    }
    return $res;
}, ['csrf' => true, 'admin' => true, 'name' => 'radar/fetch']);
