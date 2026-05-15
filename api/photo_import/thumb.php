<?php
declare(strict_types=1);

/**
 * api/photo_import/thumb.php — Serve inline thumbnail of a local image file.
 * GET  ?p=<base64-encoded-path>
 * csrf: yes (X-CSRF-Token header) | admin: yes
 *
 * Returns image/jpeg binary — not JSON — so cannot use ajax_endpoint().
 * Auth + CSRF are enforced manually before any output.
 *
 * PERF-18: passes $orientation from read_photo_exif() to generate_photo_thumb()
 * so generate_photo_thumb does not have to re-read EXIF internally.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/photo_helper.php';
require_once __DIR__ . '/../../includes/security.php';

// ── Admin gate ──────────────────────────────────────────────────────────────
if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    exit;
}

// ── CSRF (X-CSRF-Token header or _csrf_token POST field) ───────────────────
if (!csrf_verify()) {
    http_response_code(403);
    exit;
}

// ── Validate path ───────────────────────────────────────────────────────────
$encoded = $_GET['p'] ?? '';
$path    = base64_decode($encoded, true);

if ($path === false) {
    http_response_code(400);
    exit;
}

$real = realpath($path);
if ($real === false) {
    http_response_code(404);
    exit;
}
$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || !is_readable($real)) {
    http_response_code(404);
    exit;
}

// ── ETag cache (mtime + size) ───────────────────────────────────────────────
$mtime = filemtime($real);
$size  = filesize($real);
$etag  = '"' . md5($real . $mtime . $size) . '"';
header('ETag: ' . $etag);
header('Cache-Control: private, max-age=3600');
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
    http_response_code(304);
    exit;
}

// ── PERF-18: read EXIF orientation once; pass to thumb generator ────────────
$orientation = null;
if ($ext === 'jpg' || $ext === 'jpeg') {
    if (extension_loaded('exif')) {
        $exifData = @exif_read_data($real);
        $orientation = isset($exifData['Orientation']) ? (int)$exifData['Orientation'] : null;
    }
}

// ── Try embedded EXIF thumbnail first (fast — does not decode full image) ───
if (extension_loaded('exif') && ($ext === 'jpg' || $ext === 'jpeg')) {
    $thumbData = @exif_thumbnail($real);
    if ($thumbData !== false && strlen($thumbData) > 100) {
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . strlen($thumbData));
        echo $thumbData;
        exit;
    }
}

// ── Fallback: GD resize to temp file ────────────────────────────────────────
$tmp = sys_get_temp_dir() . '/gpx_imp_' . md5($real . $mtime) . '.jpg';
if (!file_exists($tmp)) {
    generate_photo_thumb($real, $tmp, 120, $orientation);
}

if (!file_exists($tmp)) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
exit;
