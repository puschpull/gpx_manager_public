<?php
declare(strict_types=1);

/**
 * api/photos/bulk.php — Bulk delete or bulk assign photos
 *
 * Dispatcher on ?action= parameter:
 *   bulk_delete — POST photo_ids[]
 *   bulk_assign — POST photo_ids[], track_id
 *
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

    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if ($action === 'bulk_delete') {
        $ids = array_filter(array_map('intval', (array)($_POST['photo_ids'] ?? [])));
        if (empty($ids)) {
            http_response_code(400);
            return ['ok' => false, 'error' => 'Žádná ID.'];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $pdo->prepare("SELECT id, filename, virtual_track_id FROM track_photos WHERE id IN ($placeholders)");
        $rows->execute($ids);
        $toDelete = $rows->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($toDelete as $row) {
            @unlink(uploads_fs('photos/' . $row['filename']));
            @unlink(uploads_fs('photos/thumbs/' . $row['filename']));
        }
        $pdo->prepare("DELETE FROM track_photos WHERE id IN ($placeholders)")->execute($ids);

        // Dotčené virtuální trasy → přepočítat statistiky + náhledy
        $affectedVts = array_unique(array_filter(array_map(
            static fn($r) => (int)($r['virtual_track_id'] ?? 0),
            $toDelete
        )));
        foreach ($affectedVts as $vt) {
            vt_recompute_and_save($pdo, $vt);
            @vt_generate_thumb($pdo, $vt);
        }

        return ['ok' => true, 'deleted' => count($toDelete)];
    }

    if ($action === 'bulk_assign') {
        $ids = array_filter(array_map('intval', (array)($_POST['photo_ids'] ?? [])));
        if (empty($ids)) {
            http_response_code(400);
            return ['ok' => false, 'error' => 'Žádná ID.'];
        }

        // '' = nepřiřadit | 'N' = GPX trasa | 'vN' = virtuální trasa
        [$trackId, $virtualTrackId] = vt_parse_assign_target($_POST['track_id'] ?? '');

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // Staré virtuální trasy dotčených fotek (pro přepočet po odebrání)
        $oldStmt = $pdo->prepare("SELECT DISTINCT virtual_track_id FROM track_photos WHERE id IN ($placeholders) AND virtual_track_id IS NOT NULL");
        $oldStmt->execute($ids);
        $affected = array_map('intval', $oldStmt->fetchAll(\PDO::FETCH_COLUMN));

        $params = array_merge([$trackId, $virtualTrackId], $ids);
        $pdo->prepare("UPDATE track_photos SET track_id = ?, virtual_track_id = ? WHERE id IN ($placeholders)")->execute($params);

        if ($virtualTrackId) {
            $affected[] = $virtualTrackId;
        }
        foreach (array_unique(array_filter($affected)) as $vt) {
            vt_recompute_and_save($pdo, (int)$vt);
            @vt_generate_thumb($pdo, (int)$vt);
        }

        return ['ok' => true, 'updated' => count($ids)];
    }

    http_response_code(400);
    return ['ok' => false, 'error' => 'Unknown action. Use ?action=bulk_delete or ?action=bulk_assign'];
}, ['csrf' => true, 'admin' => true, 'name' => 'photos/bulk']);
