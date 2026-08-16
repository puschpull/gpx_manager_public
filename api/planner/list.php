<?php
declare(strict_types=1);

/**
 * api/planner/list.php — seznam uložených plánů (bez geometrie)
 * GET ?with_pos=1 | csrf: no | admin: ne — návštěvník smí číst jen s povoleným
 * plánovačem ve Viditelných stránkách (prohlížení plánů je povoleno,
 * ukládání/mazání zůstává admin-only v save.php/delete.php).
 *
 * with_pos=1 přidá ke každému plánu těžiště (lat/lon) a příznak, zda má
 * spočítanou geometrii. Používá překryv plánu na detailu trasy, aby mohl
 * předvybrat nejbližší plán. Geometrie je MEDIUMTEXT, takže se dekóduje jen
 * na vyžádání — běžný výpis v plánovači zůstává lehký.
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
    $withPos = ($_GET['with_pos'] ?? '') === '1';
    $cols = 'p.id, p.name, p.profile, p.plan_date, p.track_id, p.length_m, p.duration_s,'
          . ' p.ascent, p.descent, p.updated_at'
          . ($withPos ? ', p.geometry' : '');

    // LEFT JOIN, ne INNER: smazaná trasa nesmí plán vyřadit z výpisu —
    // takový plán se chová, jako by uskutečněný nebyl.
    $rows = $pdo->query("
        SELECT $cols, t.track_name AS t_name, t.filename AS t_file, t.date_start AS t_date
        FROM planned_routes p
        LEFT JOIN tracks t ON t.id = p.track_id
        ORDER BY COALESCE(p.plan_date, DATE(p.updated_at)) DESC, p.id DESC
    ")->fetchAll(\PDO::FETCH_ASSOC);

    return ['ok' => true, 'plans' => array_map(static function ($r) use ($withPos) {
        $plan = [
            'id'         => (int)$r['id'],
            'name'       => $r['name'],
            'profile'    => $r['profile'],
            'plan_date'  => $r['plan_date'],
            'length_m'   => $r['length_m']   !== null ? (int)$r['length_m']   : null,
            'duration_s' => $r['duration_s'] !== null ? (int)$r['duration_s'] : null,
            'ascent'     => $r['ascent']     !== null ? (int)$r['ascent']     : null,
            'descent'    => $r['descent']    !== null ? (int)$r['descent']    : null,
            'updated_at' => $r['updated_at'],
            // track_id drží i tehdy, když trasa mezitím zmizela; ostatní pole
            // jsou pak null a UI to ukáže jako neuskutečněný plán.
            'track_id'   => $r['t_name'] !== null || $r['t_file'] !== null ? (int)$r['track_id'] : null,
            'track_name' => $r['t_name'] ?: $r['t_file'],
            'track_date' => $r['t_date'],
        ];
        if ($withPos) {
            $geom = $r['geometry'] !== null ? json_decode((string)$r['geometry'], true) : null;
            $plan['has_geometry'] = is_array($geom) && count($geom) > 1;
            $plan['lat'] = null;
            $plan['lon'] = null;
            if ($plan['has_geometry']) {
                // Těžiště stačí spočítat z řídkého vzorku — slouží jen k seřazení
                // plánů podle vzdálenosti od trasy, ne k žádnému výpočtu.
                $n = count($geom);
                $step = max(1, (int)floor($n / 100));
                $sLat = 0.0; $sLon = 0.0; $c = 0;
                for ($i = 0; $i < $n; $i += $step) {
                    if (!isset($geom[$i][0], $geom[$i][1])) continue;
                    $sLat += (float)$geom[$i][0];
                    $sLon += (float)$geom[$i][1];
                    $c++;
                }
                if ($c > 0) {
                    $plan['lat'] = round($sLat / $c, 6);
                    $plan['lon'] = round($sLon / $c, 6);
                }
            }
        }
        return $plan;
    }, $rows)];
}, ['csrf' => false, 'admin' => false, 'name' => 'planner/list']);
