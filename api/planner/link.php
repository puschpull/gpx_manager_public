<?php
declare(strict_types=1);

/**
 * api/planner/link.php — propojení plánu se skutečně ušlou trasou
 * POST plan_id, track_id (0 = zrušit propojení)
 * csrf: yes | admin: yes
 *
 * Jeden plán má nejvýš jednu trasu. Naopak to neomezujeme — k jedné
 * trase může vést víc plánů (varianty téhož výšlapu).
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';

ajax_endpoint(function () use ($pdo): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['ok' => false, 'error' => 'Method not allowed'];
    }

    $planId  = (int)($_POST['plan_id'] ?? 0);
    $trackId = (int)($_POST['track_id'] ?? 0);
    if ($planId <= 0) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Chybí plán.'];
    }

    $st = $pdo->prepare('SELECT id FROM planned_routes WHERE id = ?');
    $st->execute([$planId]);
    if (!$st->fetchColumn()) {
        http_response_code(404);
        return ['ok' => false, 'error' => 'Plán neexistuje.'];
    }

    if ($trackId > 0) {
        $st = $pdo->prepare('SELECT id FROM tracks WHERE id = ?');
        $st->execute([$trackId]);
        if (!$st->fetchColumn()) {
            http_response_code(404);
            return ['ok' => false, 'error' => 'Trasa neexistuje.'];
        }
    }

    $pdo->prepare('UPDATE planned_routes SET track_id = ? WHERE id = ?')
        ->execute([$trackId > 0 ? $trackId : null, $planId]);

    return ['ok' => true, 'plan_id' => $planId, 'track_id' => $trackId > 0 ? $trackId : null];
}, ['csrf' => true, 'admin' => true, 'name' => 'planner/link']);
