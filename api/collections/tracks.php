<?php
declare(strict_types=1);

/**
 * api/collections/tracks.php — add / remove / reorder tracks of a collection
 * POST collection_id, action = add | remove | order, track_ids[] (order: the full new order)
 * csrf: yes | admin: yes
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';

ajax_endpoint(function () use ($pdo): array {
    $cid    = (int)($_POST['collection_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $ids    = array_values(array_unique(array_filter(
        array_map('intval', (array)($_POST['track_ids'] ?? [])),
        static fn($v) => $v > 0
    )));
    if ($cid <= 0 || !in_array($action, ['add', 'remove', 'order'], true) || !$ids || count($ids) > 1000) {
        http_response_code(400);
        return ['ok' => false, 'error' => t('api_bad_param', 'Invalid parameter')];
    }
    $ex = $pdo->prepare('SELECT COUNT(*) FROM collections WHERE id = ?');
    $ex->execute([$cid]);
    if ((int)$ex->fetchColumn() === 0) {
        http_response_code(404);
        return ['ok' => false, 'error' => 'Not found'];
    }

    $pdo->beginTransaction();
    try {
        if ($action === 'add') {
            $pos = $pdo->prepare('SELECT COALESCE(MAX(position), 0) FROM collection_tracks WHERE collection_id = ?');
            $pos->execute([$cid]);
            $next = (int)$pos->fetchColumn();
            // INSERT ... SELECT skips ids that are not tracks; IGNORE skips existing members
            $ins = $pdo->prepare('INSERT IGNORE INTO collection_tracks (collection_id, track_id, position)
                                  SELECT ?, id, ? FROM tracks WHERE id = ?');
            foreach ($ids as $tid) {
                $ins->execute([$cid, ++$next, $tid]);
            }
        } elseif ($action === 'remove') {
            $del = $pdo->prepare('DELETE FROM collection_tracks WHERE collection_id = ? AND track_id = ?');
            foreach ($ids as $tid) {
                $del->execute([$cid, $tid]);
            }
        } else {
            $upd = $pdo->prepare('UPDATE collection_tracks SET position = ? WHERE collection_id = ? AND track_id = ?');
            foreach ($ids as $i => $tid) {
                $upd->execute([$i + 1, $cid, $tid]);
            }
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $cnt = $pdo->prepare('SELECT COUNT(*) FROM collection_tracks WHERE collection_id = ?');
    $cnt->execute([$cid]);
    return ['ok' => true, 'count' => (int)$cnt->fetchColumn()];
}, ['csrf' => true, 'admin' => true, 'name' => 'collections/tracks']);
