<?php
declare(strict_types=1);

/**
 * api/photo_import/exif.php — Batch EXIF read + dedup check + auto-assign preview.
 * POST  paths[]
 * csrf: yes (X-CSRF-Token header) | admin: yes
 *
 * PERF-16: batch dedup via WHERE orig_name IN (?, ...) instead of per-file SELECT.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/photo_helper.php';
require_once __DIR__ . '/../../includes/ajax.php';

/**
 * Validate that $path points to a local image file with an allowed extension.
 * Mirrors is_safe_image_path() from the original photo_import.php.
 */
function _pi_safe_image_path(string $path): bool
{
    $real = realpath($path);
    if ($real === false) return false;
    $ext  = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);
}

ajax_endpoint(function (): array {
    global $pdo;

    $paths = $_POST['paths'] ?? [];
    if (!is_array($paths) || empty($paths)) {
        return ['ok' => false, 'msg' => 'Chybi paths'];
    }

    // ── PERF-16: batch dedup — single IN query instead of per-file SELECT ──
    $origNames = [];
    foreach ($paths as $path) {
        $origNames[] = basename((string)$path);
    }
    $origNames = array_unique($origNames);

    $placeholders = implode(',', array_fill(0, count($origNames), '?'));
    $dupStmt = $pdo->prepare(
        "SELECT orig_name FROM track_photos WHERE orig_name IN ($placeholders)"
    );
    $dupStmt->execute(array_values($origNames));
    $dupSet = array_flip($dupStmt->fetchAll(\PDO::FETCH_COLUMN));

    // ── Per-file EXIF + auto-assign ────────────────────────────────────────
    $results = [];
    foreach ($paths as $path) {
        $path = (string)$path;
        if (!_pi_safe_image_path($path) || !is_readable($path)) {
            $results[] = ['path' => $path, 'error' => true];
            continue;
        }

        $exif     = read_photo_exif($path);
        $origName = basename($path);
        $isDup    = isset($dupSet[$origName]);

        // Auto-assign preview (no DB insert)
        $trackId   = null;
        $trackName = null;
        if ($exif['lat'] !== null && $exif['lon'] !== null && $exif['taken_at'] !== null) {
            $trackId = auto_assign_photo_to_track($exif['lat'], $exif['lon'], $exif['taken_at']);
            if ($trackId) {
                $r = $pdo->prepare("SELECT track_name, filename FROM tracks WHERE id = ?");
                $r->execute([$trackId]);
                $row = $r->fetch(\PDO::FETCH_ASSOC);
                $trackName = $row ? ($row['track_name'] ?: $row['filename']) : null;
            }
        }

        $results[] = [
            'path'       => $path,
            'is_dup'     => $isDup,
            'has_gps'    => ($exif['lat'] !== null && $exif['lon'] !== null),
            'lat'        => $exif['lat'],
            'lon'        => $exif['lon'],
            'taken_at'   => $exif['taken_at'],
            'track_id'   => $trackId,
            'track_name' => $trackName,
        ];
    }

    return ['ok' => true, 'results' => $results];
}, ['csrf' => true, 'admin' => true, 'name' => 'photo_import/exif']);
