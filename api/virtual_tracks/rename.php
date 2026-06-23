<?php
declare(strict_types=1);

/**
 * api/virtual_tracks/rename.php — přejmenuje virtuální trasu
 * POST id, name
 * csrf: yes | admin: yes
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';

ajax_endpoint(function () use ($pdo): array {
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($id <= 0) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Chybí id'];
    }
    if ($name === '') {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Název nesmí být prázdný'];
    }
    $name = mb_substr($name, 0, 255);
    $stmt = $pdo->prepare("UPDATE virtual_tracks SET name = ? WHERE id = ?");
    $stmt->execute([$name, $id]);

    return ['ok' => true, 'name' => $name];
}, ['csrf' => true, 'admin' => true, 'name' => 'virtual_tracks/rename']);
