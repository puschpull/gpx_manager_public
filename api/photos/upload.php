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
            mkdir($tmpDir, 0700, true);

            // Rozbaluje se jen to, co je potřeba — položku po položce, žádné
            // extractTo(). Dřív se rozbalil celý ZIP (i ne-obrázky) bez hlídání
            // velikosti, takže „ZIP bomba" (malý ZIP, gigabajty po rozbalení)
            // mohla zaplnit disk. Každý obrázek se zapíše pod VLASTNÍM jménem,
            // cesta uvnitř ZIPu (i s ../) se na disku vůbec nepoužije.
            $imageExts  = ['jpg', 'jpeg', 'png', 'webp'];
            $imgLimit   = 200;                        // fotek z jednoho ZIPu
            $entryLimit = 5000;                       // položek, které se vůbec prohlédnou
            $totalLimit = 2 * 1024 * 1024 * 1024;     // součet rozbalených dat
            $imgCount   = 0;
            $totalBytes = 0;
            $stopMsg    = null;

            $numEntries = min($zip->numFiles, $entryLimit);
            if ($zip->numFiles > $entryLimit) {
                $results[] = ['ok' => false, 'file' => $origName,
                              'msg' => "ZIP má {$zip->numFiles} položek — prohlédnuto jen prvních {$entryLimit}"];
            }

            for ($e = 0; $e < $numEntries; $e++) {
                $st = $zip->statIndex($e);
                if ($st === false || str_ends_with($st['name'], '/')) continue;   // složka
                $entryName = basename(str_replace('\\', '/', $st['name']));
                $ext = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
                if (!in_array($ext, $imageExts, true)) continue;

                if ($imgCount >= $imgLimit) {
                    $stopMsg = "Limit {$imgLimit} fotek/ZIP — zbytek přeskočen";
                    break;
                }
                // Deklarovaná velikost ve hlavičce ZIPu — rychlé odmítnutí
                if ($st['size'] > PHOTO_MAX_BYTES) {
                    $results[] = ['ok' => false, 'file' => $entryName,
                                  'msg' => 'Fotku nelze zpracovat: soubor je příliš velký (max ' . (PHOTO_MAX_BYTES >> 20) . ' MB)'];
                    continue;
                }
                if ($totalBytes + $st['size'] > $totalLimit) {
                    $stopMsg = 'ZIP je po rozbalení příliš velký (max ' . ($totalLimit >> 30) . ' GB) — zbytek přeskočen';
                    break;
                }

                // Hlavičce se nevěří: kopíruje se nejvýš limit + 1 bajt
                $in = $zip->getStream($st['name']);
                if ($in === false) {
                    $results[] = ['ok' => false, 'file' => $entryName, 'msg' => 'Položku ZIPu nelze přečíst'];
                    continue;
                }
                $outPath = $tmpDir . '/' . $e . '.' . $ext;
                $out = fopen($outPath, 'wb');
                $written = $out ? stream_copy_to_stream($in, $out, PHOTO_MAX_BYTES + 1) : false;
                fclose($in);
                if ($out) fclose($out);

                if ($written === false) {
                    @unlink($outPath);
                    $results[] = ['ok' => false, 'file' => $entryName, 'msg' => 'Položku ZIPu nelze rozbalit'];
                    continue;
                }
                if ($written > PHOTO_MAX_BYTES) {
                    @unlink($outPath);
                    $results[] = ['ok' => false, 'file' => $entryName,
                                  'msg' => 'Fotku nelze zpracovat: soubor je příliš velký (max ' . (PHOTO_MAX_BYTES >> 20) . ' MB)'];
                    continue;
                }
                $totalBytes += $written;

                $results[] = process_single_photo($outPath, $entryName, (int)$written);
                @unlink($outPath);          // uvolnit místo hned, ne až na konci
                $imgCount++;
            }
            $zip->close();
            if ($stopMsg !== null) {
                $results[] = ['ok' => false, 'file' => '…', 'msg' => $stopMsg];
            }

            _cleanup_dir($tmpDir);
            continue;
        }

        // ── Jednotlivá fotka ──
        $results[] = process_single_photo($tmpPath, $origName, $size);
    }

    return ['results' => $results];
}, ['csrf' => true, 'admin' => true, 'name' => 'photos/upload']);
