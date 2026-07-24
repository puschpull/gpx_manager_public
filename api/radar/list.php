<?php
declare(strict_types=1);

/**
 * api/radar/list.php — seznam stažených radarových snímků ČHMÚ pro trasu
 * GET ?track_id= | csrf: no | admin: no (read-only, stejně jako fotky trasy)
 *
 * Vrací snímky seřazené v čase; `t` je UNIX čas v ms (UTC) odpovídající
 * konci 5minutového intervalu měření.
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';

ajax_endpoint(function (): array {
    $trackId = (int)($_GET['track_id'] ?? 0);
    if ($trackId <= 0) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Chybí track_id.'];
    }

    $dir = uploads_fs('radar/' . $trackId . '/');
    $frames = [];
    if (is_dir($dir)) {
        foreach (glob($dir . '*.png') ?: [] as $f) {
            $name = basename($f, '.png');           // YYYYMMDDHHMM (UTC)
            if (!preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})$/', $name, $m)) continue;
            $ts = gmmktime((int)$m[4], (int)$m[5], 0, (int)$m[2], (int)$m[3], (int)$m[1]);
            $frames[] = [
                't'   => $ts * 1000,
                'url' => uploads_url('radar/' . $trackId . '/' . $name . '.png') . '?v=' . filemtime($f),
            ];
        }
        usort($frames, static fn($a, $b) => $a['t'] <=> $b['t']);
    }

    return ['ok' => true, 'frames' => $frames, 'count' => count($frames)];
}, ['csrf' => false, 'admin' => false, 'name' => 'radar/list']);
