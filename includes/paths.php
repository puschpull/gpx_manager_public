<?php
declare(strict_types=1);

/**
 * ===========================================================
 *  paths.php — Filesystem & URL path helpers
 *  Uploads directory resolution, URL shortcuts, safe path
 *  validation. All path-traversal guards live here.
 * ===========================================================
 */

/**
 * Returns the absolute filesystem path to uploads/ (trailing separator)
 * or to a file/subdirectory inside uploads/.
 * Default: <app-root>/uploads/
 * Override via app_config 'uploads_fs_path'.
 */
function uploads_fs(string $relative = ''): string {
    static $base = null;
    if ($base === null) {
        $cfg = function_exists('get_app_config') ? trim((string)get_app_config('uploads_fs_path', '')) : '';
        if ($cfg !== '') {
            $base = rtrim($cfg, "/\\") . DIRECTORY_SEPARATOR;
        } else {
            $base = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
        }
    }
    if ($relative === '') return $base;

    // Strip any leading slashes/backslashes from the caller-supplied segment.
    $clean     = ltrim($relative, "/\\");
    $candidate = $base . $clean;

    // Guard: verify the resolved parent directory stays inside $base.
    // If $base itself does not exist yet (fresh install), skip the check
    // and return the raw concatenation so callers can mkdir() safely.
    $realBase = realpath($base);
    if ($realBase === false) {
        return $candidate; // base doesn't exist yet — allow creation
    }

    // Normalise the separator used by realpath so strpos works on Windows too.
    $realBase = rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    // Resolve as deep as possible: use dirname so a not-yet-created file
    // inside a valid subdir is still accepted (e.g. 'photos/thumbs/foo.png').
    $parent     = dirname($candidate);
    $realParent = realpath($parent);
    if ($realParent !== false) {
        $realParent = rtrim($realParent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strpos($realParent, $realBase) !== 0) {
            throw new RuntimeException("Path traversal blocked: $relative");
        }
    }

    return $candidate;
}

/**
 * Returns the URL to uploads/ for use in <img src>, <a href>, etc.
 * Default: 'uploads/' (relative to the current page).
 * Override via app_config 'uploads_url'.
 */
function uploads_url(string $relative = ''): string {
    static $base = null;
    if ($base === null) {
        $cfg = function_exists('get_app_config') ? trim((string)get_app_config('uploads_url', '')) : '';
        $base = $cfg !== '' ? rtrim($cfg, '/') . '/' : 'uploads/';
    }
    return $base . ltrim($relative, '/');
}

/** URL shortcut for a photo thumbnail. */
function photo_thumb_url(string $filename): string {
    return uploads_url('photos/thumbs/' . rawurlencode($filename));
}

/** URL shortcut for a full-size photo. */
function photo_full_url(string $filename): string {
    return uploads_url('photos/' . rawurlencode($filename));
}

/**
 * Cache-busting pro lokální JS/CSS: vrátí "cesta?v=<mtime souboru>".
 * Po nasazení nové verze se změní URL → prohlížeč si stáhne aktuální
 * soubor bez tvrdého refreshe (žádné zastaralé skripty v cache).
 */
function asset(string $relative): string {
    $fs = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relative, '/'));
    return $relative . (is_file($fs) ? '?v=' . filemtime($fs) : '');
}

/**
 * Vrátí "?v=<mtime>" pro cache-busting, pokud soubor v uploads existuje (jinak "").
 * Díky tomu se po přepsání souboru se stejným názvem (in-place úprava trasy,
 * přegenerování náhledu) změní URL a prohlížeč načte novou verzi bez tvrdého refreshe.
 */
function uploads_cache_bust(string $relative): string {
    try {
        $fs = uploads_fs($relative);
    } catch (\Throwable $e) {
        return '';
    }
    return is_file($fs) ? '?v=' . filemtime($fs) : '';
}

/** URL shortcut for a GPX file stored in uploads/. */
function gpx_url(string $filename): string {
    return uploads_url(rawurlencode($filename)) . uploads_cache_bust($filename);
}

/** URL shortcut for a track thumbnail image. */
function thumb_url(string $filename): string {
    return uploads_url('thumbs/' . rawurlencode($filename)) . uploads_cache_bust('thumbs/' . $filename);
}

/**
 * Removes every file a track owns in uploads/: the GPX itself, its in-place
 * filter backup, the generated thumbnail and any downloaded CHMI radar frames.
 *
 * Single source of truth for both delete.php (single track) and
 * api_bulk_action.php (bulk delete) — they used to drift apart.
 * Call AFTER the database rows are gone; never throws.
 */
function delete_track_files(int $trackId, string $filename): void {
    if ($filename !== '') {
        $gpx = uploads_fs($filename);
        if (is_file($gpx))          @unlink($gpx);
        if (is_file($gpx . '.bak')) @unlink($gpx . '.bak');

        $thumb = uploads_fs('thumbs/' . pathinfo($filename, PATHINFO_FILENAME) . '.png');
        if (is_file($thumb)) @unlink($thumb);
    }

    if ($trackId > 0) {
        $radarDir = uploads_fs('radar/' . $trackId . '/');
        if (is_dir($radarDir)) {
            foreach (glob($radarDir . '*.png') ?: [] as $png) {
                @unlink($png);
            }
            @rmdir($radarDir);
        }
    }
}

/**
 * Validates that $path resolves to a file inside the uploads/ directory
 * and that the extension is an accepted image type.
 * Returns true only when the path is safe and the file exists.
 */
function is_safe_image_path(string $path): bool {
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return false;

    $real = realpath($path);
    if ($real === false) return false;

    $base = realpath(uploads_fs());
    if ($base === false) return false;

    $base = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return strpos($real . DIRECTORY_SEPARATOR, $base) === 0;
}
