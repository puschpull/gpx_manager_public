<?php
declare(strict_types=1);

/**
 * api/virtual_tracks/create.php — vytvoří virtuální trasy z nepřiřazených fotek
 * POST gap_hours, gap_km, min_photos
 * Vytvoří řádky virtual_tracks + nastaví virtual_track_id u fotek.
 * csrf: yes | admin: yes
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';
require_once __DIR__ . '/../../includes/virtual_track_helper.php';

ajax_endpoint(function () use ($pdo): array {
    $gapHours  = max(0.1, (float)($_POST['gap_hours'] ?? 4));
    $gapKm     = max(0.1, (float)($_POST['gap_km']    ?? 5));
    $minPhotos = max(2,   (int)($_POST['min_photos']  ?? 3));

    $photos = vt_fetch_candidates($pdo);
    $res    = vt_cluster_photos($photos, $gapHours, $gapKm, $minPhotos);

    if (empty($res['clusters'])) {
        return ['ok' => true, 'created' => 0, 'assigned' => 0, 'message' => 'Žádné výlety k vytvoření.'];
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

    // Volitelné uživatelské názvy z generátoru (v pořadí shluků). Prázdné → datum-only název.
    $names = (isset($_POST['names']) && is_array($_POST['names'])) ? $_POST['names'] : [];

    $created  = 0;
    $assigned = 0;
    $newVtIds = [];

    $pdo->beginTransaction();
    try {
        foreach ($res['clusters'] as $i => $c) {
            $s    = vt_compute_stats($c);
            $name = $s['name'];
            if (array_key_exists($i, $names)) {
                $cand = trim((string)$names[$i]);
                if ($cand !== '') {
                    $name = mb_substr($cand, 0, 255);
                }
            }
            $insVt->execute([
                ':name'         => $name,
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
            $vtId = (int)$pdo->lastInsertId();
            $created++;

            foreach ($c as $p) {
                $assignStmt->execute([':vt' => $vtId, ':id' => (int)$p['id']]);
                $assigned++;
            }
            $newVtIds[] = $vtId;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Náhledy mapy (po commitu — stahuje OSM dlaždice, ať nedrží transakci)
    foreach ($newVtIds as $vtId) {
        @vt_generate_thumb($pdo, $vtId);
    }

    return [
        'ok'       => true,
        'created'  => $created,
        'assigned' => $assigned,
        'rejected' => count($res['rejected']),
        'message'  => "Vytvořeno {$created} virtuálních tras, přiřazeno {$assigned} fotek.",
    ];
}, ['csrf' => true, 'admin' => true, 'name' => 'virtual_tracks/create']);
