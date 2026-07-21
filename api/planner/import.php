<?php
declare(strict_types=1);

/**
 * api/planner/import.php — import plánů z JSON (z api/planner/export.php)
 * POST data (JSON string), replace? (0/1 — smazat stávající před importem)
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

    $raw = (string)($_POST['data'] ?? '');
    if ($raw === '' || strlen($raw) > 8 * 1024 * 1024) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Chybí data nebo je soubor příliš velký (max 8 MB).'];
    }

    $doc = json_decode($raw, true);
    if (!is_array($doc) || ($doc['type'] ?? '') !== 'planned_routes' || !is_array($doc['plans'] ?? null)) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Neplatný soubor (očekáván export plánů z GPX Manageru).'];
    }
    $plans = $doc['plans'];
    if (count($plans) > 1000) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Příliš mnoho plánů v souboru (max 1000).'];
    }

    $allowedProfiles = ['foot_fast', 'foot_hiking', 'bike_road', 'bike_mountain', 'car_fast', 'car_short'];
    $intOrNull = static fn($v) => (is_numeric($v)) ? (int)$v : null;

    $replace  = !empty($_POST['replace']);
    $imported = 0;
    $skipped  = 0;

    $pdo->beginTransaction();
    try {
        if ($replace) {
            $pdo->exec('DELETE FROM planned_routes');
        }

        $ins = $pdo->prepare('INSERT INTO planned_routes
            (name, profile, plan_date, waypoints, geometry,
             length_m, duration_s, ascent, descent, note)
            VALUES (:name, :profile, :plan_date, :waypoints, :geometry,
                    :length_m, :duration_s, :ascent, :descent, :note)');

        foreach ($plans as $p) {
            if (!is_array($p)) { $skipped++; continue; }

            $name = trim((string)($p['name'] ?? ''));
            if ($name === '') { $skipped++; continue; }
            $name = mb_substr($name, 0, 120);

            // Waypointy: [[lat,lon,manual?],...], 2–500 platných bodů
            $wpts = $p['waypoints'] ?? null;
            if (!is_array($wpts) || count($wpts) < 2 || count($wpts) > 500) { $skipped++; continue; }
            $cleanWpts = [];
            $bad = false;
            foreach ($wpts as $w) {
                if (!is_array($w) || count($w) < 2 || !is_numeric($w[0]) || !is_numeric($w[1])) { $bad = true; break; }
                $cleanWpts[] = [round((float)$w[0], 6), round((float)$w[1], 6), (isset($w[2]) && $w[2]) ? 1 : 0];
            }
            if ($bad) { $skipped++; continue; }

            $profile = (string)($p['profile'] ?? 'foot_hiking');
            if (!in_array($profile, $allowedProfiles, true)) $profile = 'foot_hiking';

            $geometry = null;
            if (isset($p['geometry']) && is_array($p['geometry']) && count($p['geometry']) >= 2) {
                $enc = json_encode($p['geometry']);
                if (is_string($enc) && strlen($enc) <= 2 * 1024 * 1024) $geometry = $enc;
            }

            $planDate = (string)($p['plan_date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $planDate)) $planDate = '';

            $ins->execute([
                ':name'       => $name,
                ':profile'    => $profile,
                ':plan_date'  => $planDate !== '' ? $planDate : null,
                ':waypoints'  => json_encode($cleanWpts),
                ':geometry'   => $geometry,
                ':length_m'   => $intOrNull($p['length_m']   ?? null),
                ':duration_s' => $intOrNull($p['duration_s'] ?? null),
                ':ascent'     => $intOrNull($p['ascent']     ?? null),
                ':descent'    => $intOrNull($p['descent']    ?? null),
                ':note'       => trim((string)($p['note'] ?? '')) ?: null,
            ]);
            $imported++;
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['ok' => true, 'imported' => $imported, 'skipped' => $skipped, 'replaced' => $replace];
}, ['csrf' => true, 'admin' => true, 'name' => 'planner/import']);
