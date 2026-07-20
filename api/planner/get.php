<?php
declare(strict_types=1);

/**
 * api/planner/get.php — načtení jednoho plánu vč. waypointů a geometrie
 * GET ?id= | csrf: no | admin: ne — návštěvník smí číst jen s povoleným
 * plánovačem ve Viditelných stránkách (viz list.php).
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';

ajax_endpoint(function () use ($pdo): array {
    if (empty($_SESSION['is_admin'])
        && !in_array('planner', (array)get_app_config('visible_pages', all_pages()), true)) {
        http_response_code(403);
        return ['ok' => false, 'error' => 'Forbidden'];
    }
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
}, ['csrf' => false, 'admin' => false, 'name' => 'planner/get']);
