<?php
/**
 * GPX Manager — Photo Helper
 * EXIF čtení, thumbnail generátor, auto-přiřazení fotky k trase
 */

require_once __DIR__ . '/gpx_parser.php'; // pro haversine()
require_once __DIR__ . '/helpers.php';    // uploads_fs() / uploads_url()

// Zajistit existenci adresářů (jen pokud uploads/ je na lokálním filesystemu)
foreach ([uploads_fs('photos/'), uploads_fs('photos/thumbs/')] as $_dir) {
    if (!is_dir($_dir)) @mkdir($_dir, 0755, true);
}
$_htaccess = uploads_fs('photos/.htaccess');
if (is_dir(uploads_fs('photos/')) && !file_exists($_htaccess)) {
    @file_put_contents($_htaccess, "Options -Indexes\n");
}
unset($_dir, $_htaccess);


/* =========================================================
   EXIF čtení
   ========================================================= */

/**
 * Přečte GPS souřadnice, čas pořízení a rozměry z EXIF metadat JPEG.
 * Vrátí pole s klíči: lat, lon, altitude, taken_at, width, height
 * (vše může být null pokud EXIF chybí nebo neobsahuje GPS).
 */
function read_photo_exif(string $path): array {
    $result = [
        'lat'           => null,
        'lon'           => null,
        'altitude'      => null,
        'taken_at'      => null,
        'width'         => null,
        'height'        => null,
        'img_direction' => null,
    ];

    if (!extension_loaded('exif')) return $result;

    $exif = @exif_read_data($path, 'GPS,IFD0,EXIF', true);
    if (!$exif) return $result;

    // GPS souřadnice
    $gps = $exif['GPS'] ?? [];
    if (!empty($gps['GPSLatitude']) && !empty($gps['GPSLatitudeRef'])) {
        $result['lat'] = _gps_dms_to_decimal($gps['GPSLatitude'], $gps['GPSLatitudeRef']);
    }
    if (!empty($gps['GPSLongitude']) && !empty($gps['GPSLongitudeRef'])) {
        $result['lon'] = _gps_dms_to_decimal($gps['GPSLongitude'], $gps['GPSLongitudeRef']);
    }
    if (!empty($gps['GPSAltitude'])) {
        $alt = _exif_fraction($gps['GPSAltitude']);
        // GPSAltitudeRef: 0 = nad mořem, 1 = pod mořem
        $ref = isset($gps['GPSAltitudeRef']) ? (int)ord($gps['GPSAltitudeRef'][0]) : 0;
        $result['altitude'] = ($ref === 1) ? -$alt : $alt;
    }

    // Směr fotoaparátu (C2)
    if (!empty($gps['GPSImgDirection'])) {
        $dir = _exif_fraction($gps['GPSImgDirection']);
        if ($dir >= 0 && $dir < 360) {
            $result['img_direction'] = round($dir, 1);
        }
    }

    // Čas pořízení — preferovat DateTimeOriginal
    $dateStr = $exif['EXIF']['DateTimeOriginal']
            ?? $exif['EXIF']['DateTimeDigitized']
            ?? $exif['IFD0']['DateTime']
            ?? null;
    if ($dateStr) {
        // EXIF formát: "YYYY:MM:DD HH:MM:SS" → MySQL DATETIME formát
        $result['taken_at'] = preg_replace('/^(\d{4}):(\d{2}):(\d{2})/', '$1-$2-$3', trim($dateStr));
    }

    // Rozměry
    if (!empty($exif['COMPUTED']['Width'])) {
        $result['width']  = (int)$exif['COMPUTED']['Width'];
        $result['height'] = (int)$exif['COMPUTED']['Height'];
    }

    return $result;
}

/**
 * Konverze GPS DMS (stupně/minuty/sekundy) na desetinné stupně.
 * $dms = array of 3 EXIF fractions, $ref = 'N'|'S'|'E'|'W'
 */
function _gps_dms_to_decimal(array $dms, string $ref): float {
    $deg     = _exif_fraction($dms[0] ?? 0);
    $min     = _exif_fraction($dms[1] ?? 0);
    $sec     = _exif_fraction($dms[2] ?? 0);
    $decimal = $deg + ($min / 60.0) + ($sec / 3600.0);
    return ($ref === 'S' || $ref === 'W') ? -$decimal : $decimal;
}

/**
 * Vyhodnotí EXIF zlomek "numerator/denominator" nebo číslo na float.
 */
function _exif_fraction(mixed $val): float {
    if (is_string($val) && str_contains($val, '/')) {
        [$n, $d] = explode('/', $val, 2);
        return ((float)$d !== 0.0) ? (float)$n / (float)$d : 0.0;
    }
    return (float)$val;
}


/* =========================================================
   Thumbnail generátor
   ========================================================= */

/**
 * Vygeneruje thumbnail fotografie.
 * - Maximální rozměr delší strany $maxSize px, zachování poměru stran
 *   (funguje správně pro výškové i šířkové fotky)
 * - Automatická korekce EXIF orientace
 * - Výstup vždy JPEG quality 85
 * Vrátí true při úspěchu.
 */
function generate_photo_thumb(string $srcPath, string $destPath, int $maxSize = 400): bool {
    if (!extension_loaded('gd')) return false;

    $info = @getimagesize($srcPath);
    if (!$info) return false;
    [, , $type] = $info;

    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($srcPath),
        IMAGETYPE_PNG  => @imagecreatefrompng($srcPath),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : false,
        default        => false,
    };
    if (!$src) return false;

    // Korekce orientace z EXIF (jen JPEG)
    if ($type === IMAGETYPE_JPEG && extension_loaded('exif')) {
        $exifOri = @exif_read_data($srcPath);
        switch ($exifOri['Orientation'] ?? 1) {
            case 3: $src = imagerotate($src, 180, 0); break;
            case 6: $src = imagerotate($src, -90, 0); break;
            case 8: $src = imagerotate($src,  90, 0); break;
        }
    }

    $origW = imagesx($src);
    $origH = imagesy($src);

    $longestSide = max($origW, $origH);
    if ($longestSide <= $maxSize) {
        $newW = $origW;
        $newH = $origH;
    } else {
        $ratio = $maxSize / $longestSide;
        $newW  = (int)round($origW * $ratio);
        $newH  = (int)round($origH * $ratio);
    }

    $dst = imagecreatetruecolor($newW, $newH);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

    $ok = imagejpeg($dst, $destPath, 85);

    imagedestroy($src);
    imagedestroy($dst);

    return $ok;
}


/* =========================================================
   Auto-přiřazení fotky k trase
   ========================================================= */

/**
 * Minimální vzdálenost bodu (lat, lon) od GPX trackpointů v souboru.
 * Čte jen <trkpt> elementy, vrátí PHP_FLOAT_MAX pokud soubor nelze načíst.
 */
function _min_dist_to_gpx(string $filename, float $lat, float $lon): float {
    $path = uploads_fs($filename);
    if (!is_file($path)) return PHP_FLOAT_MAX;

    libxml_use_internal_errors(true);
    $xml = @simplexml_load_file($path, 'SimpleXMLElement', LIBXML_NONET);
    if (!$xml) return PHP_FLOAT_MAX;

    $minDist = PHP_FLOAT_MAX;
    // Projdi všechny trkseg/trkpt (namespace-agnostic přes xpath wildcard)
    $trkpts = $xml->xpath('//*[local-name()="trkpt"]');
    if (!$trkpts) return PHP_FLOAT_MAX;

    foreach ($trkpts as $pt) {
        $ptLat = (float)$pt['lat'];
        $ptLon = (float)$pt['lon'];
        if ($ptLat == 0.0 && $ptLon == 0.0) continue;
        $d = haversine($lat, $lon, $ptLat, $ptLon);
        if ($d < $minDist) {
            $minDist = $d;
            if ($minDist < 50) break; // < 50 m → dost přesné, netřeba číst dál
        }
    }
    return $minDist;
}

/**
 * Automaticky přiřadí fotku k nejpravděpodobnější trase podle GPS + času.
 *
 * Algoritmus:
 *   1. Trasy kde: date_start−2h ≤ taken_at ≤ date_end+2h  (+ bounds check)
 *   2. Pro kandidáty v bounds: najdi min. vzdálenost od GPX trackpointů
 *   3. Přiřaď jen pokud nejbližší trackpoint je do 500 m
 *   4. Více shod → nejmenší vzdálenost od trasy
 *
 * @return int|null  track_id nebo null pokud nelze přiřadit
 */
function auto_assign_photo_to_track(?float $lat, ?float $lon, ?string $takenAt): ?int {
    global $pdo;

    if ($lat === null || $lon === null || !$takenAt) return null;

    // Krok 1: Trasy v časovém okně ±2h s bounds
    $stmt = $pdo->prepare("
        SELECT id, filename, bounds
        FROM   tracks
        WHERE  bounds IS NOT NULL AND bounds != ''
          AND  date_start IS NOT NULL
          AND  DATE_SUB(date_start, INTERVAL 2 HOUR) <= :t
          AND  DATE_ADD(COALESCE(date_end, date_start), INTERVAL 2 HOUR) >= :t
    ");
    $stmt->execute([':t' => $takenAt]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($candidates)) return null;

    // Krok 2: Pouze trasy jejichž bounding box obsahuje GPS bod fotky
    $inBounds = [];
    foreach ($candidates as $c) {
        $b = json_decode($c['bounds'], true);
        if (!$b || !isset($b['minlat'], $b['maxlat'], $b['minlon'], $b['maxlon'])) continue;
        if ($lat >= (float)$b['minlat'] && $lat <= (float)$b['maxlat'] &&
            $lon >= (float)$b['minlon'] && $lon <= (float)$b['maxlon']) {
            $inBounds[] = $c;
        }
    }

    if (empty($inBounds)) return null; // není ani v bounds žádné trasy

    // Krok 3: Vzdálenost od skutečné GPX linie (max 500 m)
    $best     = null;
    $bestDist = PHP_FLOAT_MAX;
    foreach ($inBounds as $c) {
        $dist = _min_dist_to_gpx($c['filename'], $lat, $lon);
        if ($dist < 500 && $dist < $bestDist) { // 500 m
            $bestDist = $dist;
            $best = (int)$c['id'];
        }
    }

    return $best;
}


/* =========================================================
   Zpracování jedné fotky — resize + thumb + DB
   ========================================================= */

/**
 * Kompletní pipeline pro jednu fotografii:
 *   1. MIME kontrola
 *   2. Přečtení EXIF (GPS, čas)
 *   3. Resize na max 1600 px → uložit jako JPEG (šetří místo: 2–4 MB → ~200–400 KB)
 *   4. Thumbnail 400 px pro mapový popup
 *   5. Auto-přiřazení k trase
 *   6. INSERT do track_photos
 *
 * @param string $tmpPath   Dočasná cesta k souboru (NEMAŽE se — caller zodpovídá za cleanup)
 * @param string $origName  Původní název souboru
 * @param int    $origSize  Původní velikost v bytech
 * @return array            Result array ['ok'=>bool, ...]
 */
function process_single_photo(string $tmpPath, string $origName, int $origSize): array {
    global $pdo;

    // MIME kontrola
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);
    $allowedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowedMimes)) {
        return ['ok' => false, 'file' => $origName, 'msg' => 'Nepodporovaný formát (jen JPEG/PNG/WebP)'];
    }

    // Hash originálu pro detekci duplicit (před resizem)
    $fileHash = sha1_file($tmpPath);
    $dupCheck = $pdo->prepare("SELECT id, filename, orig_name, track_id FROM track_photos WHERE file_hash = ? LIMIT 1");
    $dupCheck->execute([$fileHash]);
    if ($dup = $dupCheck->fetch(PDO::FETCH_ASSOC)) {
        // Název přiřazené trasy
        $trackName = null;
        if ($dup['track_id']) {
            $r = $pdo->prepare("SELECT track_name, filename FROM tracks WHERE id = ?");
            $r->execute([$dup['track_id']]);
            $row = $r->fetch(PDO::FETCH_ASSOC);
            $trackName = $row ? ($row['track_name'] ?: $row['filename']) : null;
        }
        return [
            'ok'         => false,
            'duplicate'  => true,
            'file'       => $origName,
            'msg'        => 'Duplikát — fotka již byla nahrána jako „' . ($dup['orig_name'] ?: $dup['filename']) . '"',
            'thumb_url'  => photo_thumb_url($dup['filename']),
            'track_name' => $trackName,
        ];
    }

    // Přečíst EXIF před transformací (resize mění soubor)
    $exif = read_photo_exif($tmpPath);

    // Unikátní název souboru
    $filename  = photo_unique_filename($origName, $exif['taken_at']);
    $destPath  = uploads_fs('photos/' . $filename);
    $thumbPath = uploads_fs('photos/thumbs/' . $filename);

    // Resize na max 1600 px a uložit jako JPEG
    if (!generate_photo_thumb($tmpPath, $destPath, 1600)) {
        return ['ok' => false, 'file' => $origName, 'msg' => 'Nepodařilo se zpracovat obrázek (GD chyba)'];
    }

    // Thumbnail 400 px pro mapové popupy
    generate_photo_thumb($destPath, $thumbPath, 400);

    // Skutečná velikost uloženého souboru (po resiizu)
    $storedSize = (int)(@filesize($destPath) ?: 0);

    // Rozměry z uloženého souboru
    $dims    = @getimagesize($destPath);
    $storedW = $dims ? $dims[0] : $exif['width'];
    $storedH = $dims ? $dims[1] : $exif['height'];

    // Auto-přiřazení k trase
    $trackId = auto_assign_photo_to_track($exif['lat'], $exif['lon'], $exif['taken_at']);

    // DB insert
    $stmt = $pdo->prepare("
        INSERT INTO track_photos
            (track_id, filename, orig_name, lat, lon, altitude, taken_at, width, height, file_size, file_hash, img_direction)
        VALUES
            (:track_id, :filename, :orig_name, :lat, :lon, :altitude, :taken_at, :width, :height, :file_size, :file_hash, :img_direction)
    ");
    $stmt->execute([
        ':track_id'      => $trackId,
        ':filename'      => $filename,
        ':orig_name'     => $origName,
        ':lat'           => $exif['lat'],
        ':lon'           => $exif['lon'],
        ':altitude'      => $exif['altitude'],
        ':taken_at'      => $exif['taken_at'],
        ':width'         => $storedW,
        ':height'        => $storedH,
        ':file_size'     => $storedSize,
        ':file_hash'     => $fileHash,
        ':img_direction' => $exif['img_direction'],
    ]);
    $photoId = (int)$pdo->lastInsertId();

    // Název přiřazené trasy
    $trackName = null;
    if ($trackId) {
        $r = $pdo->prepare("SELECT track_name, filename FROM tracks WHERE id = ?");
        $r->execute([$trackId]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        $trackName = $row ? ($row['track_name'] ?: $row['filename']) : null;
    }

    return [
        'ok'          => true,
        'id'          => $photoId,
        'file'        => $origName,
        'filename'    => $filename,
        'thumb_url'   => photo_thumb_url($filename),
        'has_gps'     => ($exif['lat'] !== null),
        'taken_at'    => $exif['taken_at'],
        'track_id'    => $trackId,
        'track_name'  => $trackName,
        'orig_size'   => $origSize,
        'stored_size' => $storedSize,
    ];
}

/**
 * Rekurzivně smaže adresář a vše uvnitř.
 */
function _cleanup_dir(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}


/* =========================================================
   Pomocné funkce
   ========================================================= */

/**
 * Vygeneruje bezpečné jedinečné jméno souboru pro uložení fotky.
 * Formát: YYYYMMDD_HHMMSS_RAND.jpg
 */
function photo_unique_filename(string $origName, ?string $takenAt = null): string {
    $ext  = 'jpg'; // vždy ukládáme jako JPEG
    $rand = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
    if ($takenAt) {
        $ts   = strtotime($takenAt) ?: time();
        $date = date('Ymd_His', $ts);
    } else {
        $date = date('Ymd_His');
    }
    return "{$date}_{$rand}.{$ext}";
}

/* photo_thumb_url() a photo_full_url() jsou definovány v helpers.php
   (sdíleno s gpx_url(), thumb_url() — všechny respektují app_config 'uploads_url'). */
