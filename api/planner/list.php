<?php
declare(strict_types=1);

/**
 * api/planner/list.php — seznam uložených plánů (bez geometrie)
 * GET | csrf: no | admin: yes
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';

ajax_endpoint(function () use ($pdo): array {
    $rows = $pdo->query('
        SELECT id, name, profile, plan_date, length_m, duration_s, ascent, descent, updated_at
        FROM planned_routes
        ORDER BY COALESCE(plan_date, DATE(updated_at)) DESC, id DESC
    ')->fetchAll(\PDO::FETCH_ASSOC);

    return ['ok' => true, 'plans' => array_map(static fn($r) => [
        'id'         => (int)$r['id'],
        'name'       => $r['name'],
        'profile'    => $r['profile'],
        'plan_date'  => $r['plan_date'],
        'length_m'   => $r['length_m']   !== null ? (int)$r['length_m']   : null,
        'duration_s' => $r['duration_s'] !== null ? (int)$r['duration_s'] : null,
        'ascent'     => $r['ascent']     !== null ? (int)$r['ascent']     : null,
        'descent'    => $r['descent']    !== null ? (int)$r['descent']    : null,
        'updated_at' => $r['updated_at'],
    ], $rows)];
}, ['csrf' => false, 'admin' => true, 'name' => 'planner/list']);
