<?php
declare(strict_types=1);

/**
 * api/collections/delete.php — delete a collection (the tracks stay, only the list goes)
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
        return ['ok' => false, 'error' => t('api_bad_param', 'Invalid parameter')];
    }
    // collection_tracks rows go with it (ON DELETE CASCADE)
    $st = $pdo->prepare('DELETE FROM collections WHERE id = ?');
    $st->execute([$id]);
    return ['ok' => true, 'deleted' => $st->rowCount()];
}, ['csrf' => true, 'admin' => true, 'name' => 'collections/delete']);
