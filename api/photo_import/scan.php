<?php
declare(strict_types=1);

/**
 * api/photo_import/scan.php — Scan local directory for image files.
 * GET  ?dir=<path>
 * csrf: yes (X-CSRF-Token header) | admin: yes
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/ajax.php';

ajax_endpoint(function (): array {
    $dir = trim($_GET['dir'] ?? '');
    if ($dir === '' || !is_dir($dir)) {
        return ['ok' => false, 'msg' => 'Adresar neexistuje nebo nelze cist.'];
    }

    $files = [];
    $limit = 50000;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) continue;
            $files[] = [
                'path'  => $file->getRealPath(),
                'name'  => $file->getFilename(),
                'size'  => $file->getSize(),
                'mtime' => $file->getMTime(),
            ];
            if (count($files) >= $limit) break;
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => 'Chyba pri skenovani: ' . $e->getMessage()];
    }

    // Sort by mtime descending (newest first)
    usort($files, fn($a, $b) => $b['mtime'] - $a['mtime']);

    return ['ok' => true, 'files' => $files, 'total' => count($files)];
}, ['csrf' => true, 'admin' => true, 'name' => 'photo_import/scan']);
