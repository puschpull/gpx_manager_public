<?php
declare(strict_types=1);

/**
 * api/markers/delete.php — smaže značku (stránka Mapa)
 * POST id
 * csrf: yes | admin: yes
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';

ajax_endpoint(function () use ($pdo): array {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        return ['ok' => false, 'error' => t('api_bad_param', 'Invalid parameter') . ': id'];
    }
    $st = $pdo->prepare('DELETE FROM map_markers WHERE id = ?');
    $st->execute([$id]);
    return ['ok' => true, 'deleted' => $st->rowCount()];
}, ['csrf' => true, 'admin' => true, 'name' => 'markers/delete']);
