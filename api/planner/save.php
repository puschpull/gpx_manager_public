<?php
declare(strict_types=1);

/**
 * api/planner/save.php — uložení / aktualizace plánu z Plánovače
 * POST id? (0 = nový), name, profile, plan_date?, waypoints (JSON),
 *      geometry? (JSON), length_m?, duration_s?, ascent?, descent?, note?
 * csrf: yes | admin: yes
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';

ajax_endpoint(function () use ($pdo): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['ok' => false, 'error' => 'Method not allowed'];
    }

    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Chybí název plánu.'];
    }
    $name = mb_substr($name, 0, 120);

    $profile = (string)($_POST['profile'] ?? 'foot_hiking');
    $allowedProfiles = ['foot_fast', 'foot_hiking', 'bike_road', 'bike_mountain', 'car_fast', 'car_short'];
    if (!in_array($profile, $allowedProfiles, true)) {
        $profile = 'foot_hiking';
    }

    // Waypointy: JSON pole [[lat,lon],...], 2–30 platných bodů
    $wptsRaw = (string)($_POST['waypoints'] ?? '');
    $wpts = json_decode($wptsRaw, true);
    if (!is_array($wpts) || count($wpts) < 2 || count($wpts) > 30) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Neplatné waypointy (2–30 bodů).'];
    }
    $cleanWpts = [];
    foreach ($wpts as $w) {
        if (!is_array($w) || count($w) < 2 || !is_numeric($w[0]) || !is_numeric($w[1])) {
            http_response_code(400);
            return ['ok' => false, 'error' => 'Neplatný formát waypointů.'];
        }
        // 3. prvek (volitelný) = příznak ručního úseku (0/1): segment vedoucí
        // DO tohoto bodu je rovná čára místo routingu (přechod pole/lesa).
        $manual = (isset($w[2]) && $w[2]) ? 1 : 0;
        $cleanWpts[] = [round((float)$w[0], 6), round((float)$w[1], 6), $manual];
    }

    // Geometrie: volitelná cache spočítané trasy (max ~2 MB)
    $geomRaw = (string)($_POST['geometry'] ?? '');
    $geometry = null;
    if ($geomRaw !== '' && strlen($geomRaw) <= 2 * 1024 * 1024) {
        $geom = json_decode($geomRaw, true);
        if (is_array($geom) && count($geom) >= 2) {
            $geometry = json_encode($geom);
        }
    }

    $planDate = trim((string)($_POST['plan_date'] ?? ''));
    if ($planDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $planDate)) {
        $planDate = '';
    }

    $intOrNull = static fn($v) => ($v !== '' && $v !== null && is_numeric($v)) ? (int)$v : null;

    $params = [
        ':name'       => $name,
        ':profile'    => $profile,
        ':plan_date'  => $planDate !== '' ? $planDate : null,
        ':waypoints'  => json_encode($cleanWpts),
        ':geometry'   => $geometry,
        ':length_m'   => $intOrNull($_POST['length_m']   ?? null),
        ':duration_s' => $intOrNull($_POST['duration_s'] ?? null),
        ':ascent'     => $intOrNull($_POST['ascent']     ?? null),
        ':descent'    => $intOrNull($_POST['descent']    ?? null),
        ':note'       => trim((string)($_POST['note'] ?? '')) ?: null,
    ];

    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $params[':id'] = $id;
        $stmt = $pdo->prepare('UPDATE planned_routes SET
            name = :name, profile = :profile, plan_date = :plan_date,
            waypoints = :waypoints, geometry = :geometry,
            length_m = :length_m, duration_s = :duration_s,
            ascent = :ascent, descent = :descent, note = :note
            WHERE id = :id');
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            // id neexistuje → vytvořit jako nový
            unset($params[':id']);
            $id = 0;
        }
    }
    if ($id === 0) {
        $stmt = $pdo->prepare('INSERT INTO planned_routes
            (name, profile, plan_date, waypoints, geometry,
             length_m, duration_s, ascent, descent, note)
            VALUES (:name, :profile, :plan_date, :waypoints, :geometry,
                    :length_m, :duration_s, :ascent, :descent, :note)');
        $stmt->execute($params);
        $id = (int)$pdo->lastInsertId();
    }

    return ['ok' => true, 'id' => $id];
}, ['csrf' => true, 'admin' => true, 'name' => 'planner/save']);
