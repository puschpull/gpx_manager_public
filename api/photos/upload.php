<?php
declare(strict_types=1);

/**
 * api/photos/upload.php — Upload single photo or ZIP batch
 * csrf: yes | admin: yes
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';
require_once __DIR__ . '/../../includes/photo_helper.php';

ajax_endpoint(function (): array {
    if (!isset($_FILES['photos'])) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Žádné soubory.'];
    }

    $results = [];
    $files   = $_FILES['photos'];
    $count   = is_array($files['name']) ? count($files['name']) : 1;

    // SEC-018: Reject batch uploads over the per-request limit
    $maxFiles = 200;
    if ($count > $maxFiles) {
        http_response_code(400);
        return ['ok' => false, 'error' => "Max {$maxFiles} photos per upload request"];
    }

    // Normalizace na pole (pro multi-upload)
    if (!is_array($files['name'])) {
        foreach ($files as $k => $v) {
            $files[$k] = [$v];
        }
    }

    set_time_limit(300);
    ini_set('memory_limit', '256M');

    for ($i = 0; $i < $count; $i++) {
        $origName = $files['name'][$i];
        $tmpPath  = $files['tmp_name'][$i];
        $error    = $files['error'][$i];
        $size     = (int)$files['size'][$i];

        if ($error !== UPLOAD_ERR_OK) {
            $results[] = ['file' => $origName, 'ok' => false, 'msg' => 'Chyba uploadu (' . $error . ')'];
            continue;
        }

        // Detekce MIME
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        // ── ZIP soubor ──
        $isZip = in_array($mime, ['application/zip','application/x-zip','application/x-zip-compressed'])
              || strtolower(pathinfo($origName, PATHINFO_EXTENSION)) === 'zip';

        if ($isZip) {
            if (!class_exists('ZipArchive')) {
                $results[] = ['file' => $origName, 'ok' => false, 'msg' => 'PHP ZipArchive není dostupný na serveru'];
                continue;
            }
            $zip = new ZipArchive();
            if ($zip->open($tmpPath) !== true) {
                $results[] = ['file' => $origName, 'ok' => false, 'msg' => 'Nepodařilo se otevřít ZIP soubor'];
                continue;
            }

            $tmpDir = sys_get_temp_dir() . '/gpx_photos_' . uniqid('', true);
            mkdir($tmpDir, 0755, true);
            $zip->extractTo($tmpDir);
            $zip->close();

            $imageExts = ['jpg', 'jpeg', 'png', 'webp'];
            $imgLimit  = 200;
            $imgCount  = 0;
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if (!$f->isFile()) continue;
                $ext = strtolower($f->getExtension());
                if (!in_array($ext, $imageExts)) continue;
                if ($imgCount >= $imgLimit) {
                    $results[] = ['ok' => false, 'file' => '…', 'msg' => "Limit {$imgLimit} fotek/ZIP — zbytek přeskočen"];
                    break;
                }
                $results[] = process_single_photo($f->getPathname(), $f->getFilename(), (int)$f->getSize());
                $imgCount++;
            }

            _cleanup_dir($tmpDir);
            continue;
        }

        // ── Jednotlivá fotka ──
        $results[] = process_single_photo($tmpPath, $origName, $size);
    }

    return ['results' => $results];
}, ['csrf' => true, 'admin' => true, 'name' => 'photos/upload']);
