<?php
declare(strict_types=1);

/**
 * api/collections/search.php — tracks to add to a collection (admin picker)
 * GET collection_id, q (the same search as the track list)
 * csrf: no (read-only) | admin: yes
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';
require_once __DIR__ . '/../../includes/track_filter.php';
require_once __DIR__ . '/../../includes/track_title.php';

ajax_endpoint(function () use ($pdo): array {
    $cid = (int)($_GET['collection_id'] ?? 0);
    $q   = trim((string)($_GET['q'] ?? ''));
    if ($q === '') {
        return ['ok' => true, 'tracks' => []];
    }
    $f  = buildFilterSQL(['q' => $q], 't.');
    $st = $pdo->prepare("
        SELECT t.id, t.filename, t.track_name, t.alt_title, t.place_name, t.date_start,
               t.distance_km, t.activity_type,
               EXISTS(SELECT 1 FROM collection_tracks ct
                      WHERE ct.collection_id = :cid AND ct.track_id = t.id) AS member
        FROM tracks t
        WHERE {$f['where']}
        ORDER BY t.date_start DESC
        LIMIT 40
    ");
    $st->execute($f['params'] + [':cid' => $cid]);
    $out = [];
    foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $r['place_name'] = $r['place_name'] ?? '';
        $out[] = [
            'id'     => (int)$r['id'],
            'name'   => track_display_title($pdo, $r),
            'km'     => round((float)$r['distance_km'], 1),
            'member' => (bool)$r['member'],
        ];
    }
    return ['ok' => true, 'tracks' => $out];
}, ['csrf' => false, 'admin' => true, 'name' => 'collections/search']);
