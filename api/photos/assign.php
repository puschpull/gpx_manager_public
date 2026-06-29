<?php
declare(strict_types=1);

/**
 * api/photos/assign.php — Assign a photo to a track (GPX or virtual)
 * POST photo_id, track_id
 *   ''   = unassign | 'N' = GPX track N | 'vN' = virtual track N
 * csrf: yes | admin: yes
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';
require_once __DIR__ . '/../../includes/virtual_track_helper.php';

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

    [$trackId, $virtualTrackId] = vt_parse_assign_target($_POST['track_id'] ?? '');

    // Stará virtuální trasa fotky — po odebrání ji přepočítáme
    $sel = $pdo->prepare("SELECT virtual_track_id FROM track_photos WHERE id = ?");
    $sel->execute([$photoId]);
    $oldVt = (int)($sel->fetchColumn() ?: 0);

    $pdo->prepare("UPDATE track_photos SET track_id = ?, virtual_track_id = ? WHERE id = ?")
        ->execute([$trackId, $virtualTrackId, $photoId]);

    // Přepočítat statistiky + náhled dotčených virtuálních tras (stará i nová)
    foreach (array_unique(array_filter([$oldVt, $virtualTrackId])) as $vt) {
        vt_recompute_and_save($pdo, (int)$vt);
        @vt_generate_thumb($pdo, (int)$vt);
    }

    return ['ok' => true];
}, ['csrf' => true, 'admin' => true, 'name' => 'photos/assign']);
