<?php
declare(strict_types=1);

/**
 * api/virtual_tracks/update.php — úprava metadat virtuální trasy
 * POST id + libovolné z: note, color (#hex), is_favorite (0/1)
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

    $sets = [];
    $params = [':id' => $id];

    if (array_key_exists('note', $_POST)) {
        $sets[] = 'note = :note';
        $params[':note'] = mb_substr(trim((string)$_POST['note']), 0, 5000);
    }
    if (array_key_exists('color', $_POST)) {
        $color = trim((string)$_POST['color']);
        // povolíme jen #RRGGBB nebo prázdné (zrušení barvy)
        if ($color !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            http_response_code(400);
            return ['ok' => false, 'error' => 'Neplatná barva'];
        }
        $sets[] = 'color = :color';
        $params[':color'] = $color !== '' ? $color : null;
    }
    if (array_key_exists('is_favorite', $_POST)) {
        $sets[] = 'is_favorite = :fav';
        $params[':fav'] = !empty($_POST['is_favorite']) ? 1 : 0;
    }

    if (!$sets) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Nic k uložení'];
    }

    $pdo->prepare("UPDATE virtual_tracks SET " . implode(', ', $sets) . " WHERE id = :id")
        ->execute($params);

    return ['ok' => true];
}, ['csrf' => true, 'admin' => true, 'name' => 'virtual_tracks/update']);
