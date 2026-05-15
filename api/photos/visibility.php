<?php
declare(strict_types=1);

/**
 * api/photos/visibility.php — Toggle or bulk-set photo visibility
 *
 * Dispatcher on ?action= parameter:
 *   (none / toggle_visible) — POST photo_id   → toggle single photo
 *   bulk_visible             — POST photo_ids[], visible (0|1)
 *
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

    $action = $_GET['action'] ?? $_POST['action'] ?? 'toggle_visible';

    if ($action === 'bulk_visible') {
        $ids     = array_filter(array_map('intval', (array)($_POST['photo_ids'] ?? [])));
        $visible = isset($_POST['visible']) ? (int)(bool)$_POST['visible'] : null;

        if (empty($ids) || $visible === null) {
            http_response_code(400);
            return ['ok' => false, 'error' => 'Chybí parametry'];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$visible], $ids);
        $pdo->prepare("UPDATE track_photos SET visible = ? WHERE id IN ($placeholders)")->execute($params);

        return ['ok' => true, 'visible' => $visible, 'ids' => array_values($ids)];
    }

    // Default: toggle single photo visibility
    $photoId = (int)($_POST['photo_id'] ?? 0);
    if (!$photoId) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Chybí photo_id'];
    }

    $row = $pdo->prepare("SELECT visible FROM track_photos WHERE id = ?");
    $row->execute([$photoId]);
    $current = $row->fetchColumn();
    $newVal  = ($current == 0) ? 1 : 0;

    $pdo->prepare("UPDATE track_photos SET visible = ? WHERE id = ?")->execute([$newVal, $photoId]);

    return ['ok' => true, 'visible' => $newVal];
}, ['csrf' => true, 'admin' => true, 'name' => 'photos/visibility']);
