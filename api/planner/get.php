<?php
declare(strict_types=1);

/**
 * api/planner/get.php — načtení jednoho plánu vč. waypointů a geometrie
 * GET ?id= | csrf: no | admin: yes
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';

ajax_endpoint(function () use ($pdo): array {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Chybí id.'];
    }

    $stmt = $pdo->prepare('SELECT * FROM planned_routes WHERE id = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$r) {
        http_response_code(404);
        return ['ok' => false, 'error' => 'Plán nenalezen.'];
    }

    return ['ok' => true, 'plan' => [
        'id'         => (int)$r['id'],
        'name'       => $r['name'],
        'profile'    => $r['profile'],
        'plan_date'  => $r['plan_date'],
        'waypoints'  => json_decode((string)$r['waypoints'], true) ?: [],
        'geometry'   => $r['geometry'] !== null ? (json_decode((string)$r['geometry'], true) ?: null) : null,
        'length_m'   => $r['length_m']   !== null ? (int)$r['length_m']   : null,
        'duration_s' => $r['duration_s'] !== null ? (int)$r['duration_s'] : null,
        'ascent'     => $r['ascent']     !== null ? (int)$r['ascent']     : null,
        'descent'    => $r['descent']    !== null ? (int)$r['descent']    : null,
        'note'       => $r['note'],
    ]];
}, ['csrf' => false, 'admin' => true, 'name' => 'planner/get']);
