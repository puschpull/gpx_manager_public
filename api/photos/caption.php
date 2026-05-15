<?php
declare(strict_types=1);

/**
 * api/photos/caption.php — Update photo caption (TASK-04 sanitization applied)
 * POST photo_id, caption
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
    $caption = trim($_POST['caption'] ?? '');

    // Strip C0/C1 control chars except tab (\x09) and newline (\x0A).
    // Removes NUL, BEL, BS, VT, FF, CR, SO–US, DEL and C1 (0x80–0x9F).
    $caption = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $caption);
    // Hard-limit to 1000 characters (multibyte-safe).
    $caption = mb_substr($caption, 0, 1000, 'UTF-8');

    if (!$photoId) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Chybí photo_id'];
    }

    $pdo->prepare("UPDATE track_photos SET caption = ? WHERE id = ?")
        ->execute([$caption !== '' ? $caption : null, $photoId]);

    return ['ok' => true];
}, ['csrf' => true, 'admin' => true, 'name' => 'photos/caption']);
