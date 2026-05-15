<?php
declare(strict_types=1);

/**
 * api/photos/assign.php — Assign a photo to a track
 * POST photo_id, track_id (empty string = unassign)
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

    $photoId = (int)($_POST['photo_id'] ?? 0);
    $trackId = (isset($_POST['track_id']) && $_POST['track_id'] !== '')
        ? (int)$_POST['track_id']
        : null;

    if (!$photoId) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Chybí photo_id'];
    }

    $stmt = $pdo->prepare("UPDATE track_photos SET track_id = ? WHERE id = ?");
    $stmt->execute([$trackId, $photoId]);

    return ['ok' => true];
}, ['csrf' => true, 'admin' => true, 'name' => 'photos/assign']);
