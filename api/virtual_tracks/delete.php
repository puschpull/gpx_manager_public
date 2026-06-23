<?php
declare(strict_types=1);

/**
 * api/virtual_tracks/delete.php — smaže virtuální trasu
 * POST id
 * Fotky se vrátí do nepřiřazených (FK ON DELETE SET NULL → virtual_track_id = NULL).
 * Data fotek se NEMAŽOU.
 * csrf: yes | admin: yes
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';

ajax_endpoint(function () use ($pdo): array {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Chybí id'];
    }
    // ON DELETE SET NULL vrátí fotky do nepřiřazených automaticky
    $stmt = $pdo->prepare("DELETE FROM virtual_tracks WHERE id = ?");
    $stmt->execute([$id]);

    return ['ok' => true, 'deleted' => $stmt->rowCount()];
}, ['csrf' => true, 'admin' => true, 'name' => 'virtual_tracks/delete']);
