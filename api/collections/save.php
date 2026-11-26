<?php
declare(strict_types=1);

/**
 * api/collections/save.php — create or update a collection
 * POST id (0 = new), name, description, is_public
 * csrf: yes | admin: yes
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';

ajax_endpoint(function () use ($pdo): array {
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));
    if ($name === '' || mb_strlen($name) > 160 || mb_strlen($desc) > 10000) {
        http_response_code(400);
        return ['ok' => false, 'error' => t('api_bad_param', 'Invalid parameter') . ': name'];
    }
    $pub = ($_POST['is_public'] ?? '0') === '1' ? 1 : 0;

    if ($id > 0) {
        $ex = $pdo->prepare('SELECT COUNT(*) FROM collections WHERE id = ?');
        $ex->execute([$id]);
        if ((int)$ex->fetchColumn() === 0) {
            http_response_code(404);
            return ['ok' => false, 'error' => 'Not found'];
        }
        $st = $pdo->prepare('UPDATE collections SET name = ?, description = ?, is_public = ? WHERE id = ?');
        $st->execute([$name, $desc !== '' ? $desc : null, $pub, $id]);
    } else {
        $st = $pdo->prepare('INSERT INTO collections (name, description, is_public) VALUES (?, ?, ?)');
        $st->execute([$name, $desc !== '' ? $desc : null, $pub]);
        $id = (int)$pdo->lastInsertId();
    }
    return ['ok' => true, 'id' => $id];
}, ['csrf' => true, 'admin' => true, 'name' => 'collections/save']);
