<?php
declare(strict_types=1);

/**
 * api/photos/delete.php — Delete a single photo (file + DB row)
 * POST photo_id
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
    if (!$photoId) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Chybí photo_id'];
    }

    $stmt = $pdo->prepare("SELECT filename FROM track_photos WHERE id = ?");
    $stmt->execute([$photoId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if ($row) {
        @unlink(uploads_fs('photos/' . $row['filename']));
        @unlink(uploads_fs('photos/thumbs/' . $row['filename']));
        $pdo->prepare("DELETE FROM track_photos WHERE id = ?")->execute([$photoId]);
    }

    return ['ok' => true];
}, ['csrf' => true, 'admin' => true, 'name' => 'photos/delete']);
