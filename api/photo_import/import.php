<?php
declare(strict_types=1);

/**
 * api/photo_import/import.php — Import selected local photos into the project.
 * POST  paths[]
 * csrf: yes (X-CSRF-Token header) | admin: yes
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/photo_helper.php';
require_once __DIR__ . '/../../includes/ajax.php';

/**
 * Validate that $path points to a local image file with an allowed extension.
 */
function _pi_import_safe_path(string $path): bool
{
    $real = realpath($path);
    if ($real === false) return false;
    $ext  = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);
}

ajax_endpoint(function (): array {
    $paths = $_POST['paths'] ?? [];
    if (!is_array($paths) || empty($paths)) {
        return ['ok' => false, 'msg' => 'Chybi paths'];
    }

    set_time_limit(0);
    ini_set('memory_limit', '512M');

    $results = [];
    foreach ($paths as $path) {
        $path = (string)$path;
        if (!_pi_import_safe_path($path) || !is_readable($path)) {
            $results[] = ['ok' => false, 'file' => basename($path), 'msg' => 'Soubor nelze cist'];
            continue;
        }
        $result         = process_single_photo($path, basename($path), (int)filesize($path));
        $result['file'] = basename($path);
        $results[]      = $result;
    }

    return ['ok' => true, 'results' => $results];
}, ['csrf' => true, 'admin' => true, 'name' => 'photo_import/import']);
