<?php
declare(strict_types=1);

/**
 * api/virtual_tracks/resplit_apply.php — rozdělí EXISTUJÍCÍ virtuální trasu
 * na úseky podle parametrů. Původní trasa = 1. úsek (zachová si název, poznámku,
 * barvu i ⭐), zbylé úseky vzniknou jako nové virtuální trasy.
 * Malé úseky byly přilepené k sousedovi už ve vt_split_photos (nic se neztratí).
 * POST id, gap_hours, gap_km, min_photos
 * csrf: yes | admin: yes
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';
require_once __DIR__ . '/../../includes/virtual_track_helper.php';

ajax_endpoint(function () use ($pdo): array {
    $id        = (int)($_POST['id'] ?? 0);
    $gapHours  = max(0.1, (float)($_POST['gap_hours'] ?? 4));
    $gapKm     = max(0.1, (float)($_POST['gap_km']    ?? 5));
    $minPhotos = max(2,   (int)($_POST['min_photos']  ?? 3));
    if ($id <= 0) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Chybí id'];
    }

    $photos   = vt_fetch_track_photos($pdo, $id);
    $segments = vt_split_photos($photos, $gapHours, $gapKm, $minPhotos);

    if (count($segments) <= 1) {
        return [
            'ok'            => true,
            'created'       => 0,
            'segment_count' => count($segments),
            'message'       => 'Trasa se podle těchto parametrů nerozpadá — zůstává vcelku.',
        ];
    }

    $insVt = $pdo->prepare("
        INSERT INTO virtual_tracks
            (name, date_start, date_end, photo_count, distance_km, ascent, descent,
             bounds, centroid_lat, centroid_lon)
        VALUES
            (:name, :date_start, :date_end, :photo_count, :distance_km, :ascent, :descent,
             :bounds, :centroid_lat, :centroid_lon)
    ");
    $assignStmt = $pdo->prepare("UPDATE track_photos SET virtual_track_id = :vt WHERE id = :id");

    $newVtIds = [];
    $created  = 0;

    $pdo->beginTransaction();
    try {
        foreach ($segments as $i => $seg) {
            if ($i === 0) {
                // 1. úsek zůstává v původní trase — fotky už mají virtual_track_id = $id.
                continue;
            }
            $s = vt_compute_stats($seg);
            $insVt->execute([
                ':name'         => $s['name'],
                ':date_start'   => $s['date_start'],
                ':date_end'     => $s['date_end'],
                ':photo_count'  => $s['photo_count'],
                ':distance_km'  => $s['distance_km'],
                ':ascent'       => $s['ascent'],
                ':descent'      => $s['descent'],
                ':bounds'       => $s['bounds'],
                ':centroid_lat' => $s['centroid_lat'],
                ':centroid_lon' => $s['centroid_lon'],
            ]);
            $newId = (int)$pdo->lastInsertId();
            foreach ($seg as $p) {
                $assignStmt->execute([':vt' => $newId, ':id' => (int)$p['id']]);
            }
            $newVtIds[] = $newId;
            $created++;
        }
        // Původní trasa si nechala jen fotky 1. úseku → přepočítat statistiky (název se NEMĚNÍ).
        vt_recompute_and_save($pdo, $id);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Náhledy map (po commitu — stahuje OSM dlaždice, ať nedrží transakci)
    @vt_generate_thumb($pdo, $id);
    foreach ($newVtIds as $vid) {
        @vt_generate_thumb($pdo, $vid);
    }

    return [
        'ok'            => true,
        'created'       => $created,
        'segment_count' => count($segments),
        'new_ids'       => $newVtIds,
        'message'       => 'Trasa rozdělena na ' . count($segments) . " úseků — vzniklo {$created} nových tras.",
    ];
}, ['csrf' => true, 'admin' => true, 'name' => 'virtual_tracks/resplit_apply']);
