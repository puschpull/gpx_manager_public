<?php
declare(strict_types=1);

/**
 * api/planner/export.php — export všech uložených plánů jako JSON
 * (klient z něj vytvoří soubor ke stažení). GET | csrf: no | admin: yes
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';

ajax_endpoint(function () use ($pdo): array {
    $rows = $pdo->query('
        SELECT name, profile, plan_date, waypoints, geometry,
               length_m, duration_s, ascent, descent, note
        FROM planned_routes
        ORDER BY id
    ')->fetchAll(\PDO::FETCH_ASSOC);

    $plans = array_map(static function ($r) {
        return [
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
        ];
    }, $rows);

    return [
        'ok'     => true,
        'export' => [
            'app'     => 'GPX Manager',
            'type'    => 'planned_routes',
            'version' => 1,
            'count'   => count($plans),
            'plans'   => $plans,
        ],
    ];
}, ['csrf' => false, 'admin' => true, 'name' => 'planner/export']);
