<?php
declare(strict_types=1);

/**
 * api/planner/delete.php — smazání uloženého plánu
 * POST id | csrf: yes | admin: yes
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';

ajax_endpoint(function () use ($pdo): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['ok' => false, 'error' => 'Method not allowed'];
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Chybí id.'];
    }

    $pdo->prepare('DELETE FROM planned_routes WHERE id = ?')->execute([$id]);
    return ['ok' => true];
}, ['csrf' => true, 'admin' => true, 'name' => 'planner/delete']);
