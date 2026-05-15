<?php
/**
 * ===========================================================
 *  GPX Manager – Generátor náhledů tras
 *  Stáhne OSM dlaždice, nakreslí trasu přes GD, uloží PNG
 * ===========================================================
 */
require_once __DIR__ . '/gpx_parser.php';

/**
 * Převod zeměpisných souřadnic na číslo dlaždice OSM
 */
function coords_to_tile(float $lat, float $lon, int $zoom): array {
    $n    = pow(2, $zoom);
    $tileX = (int)(($lon + 180) / 360 * $n);
    $tileY = (int)((1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / M_PI) / 2 * $n);
    return [$tileX, $tileY];
}

/**
 * Převod souřadnic na pixel v rámci dlaždice
 */
function coords_to_pixel(float $lat, float $lon, int $zoom, int $tileX, int $tileY): array {
    $n    = pow(2, $zoom);
    $px   = (($lon + 180) / 360 * $n - $tileX) * 256;
    $py   = ((1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / M_PI) / 2 * $n - $tileY) * 256;
    return [(int)$px, (int)$py];
}

/**
 * Stáhne jednu OSM dlaždici, vrátí GD resource nebo null.
 * Výsledek se ukládá do lokální cache v uploads/_tile_cache/{z}/{x}/{y}.png (TTL 30 dní).
 * usleep() se volá JEN při skutečném síťovém requestu (cache miss).
 */
function fetch_tile(int $z, int $x, int $y): ?\GdImage {
    // --- Tile cache ---
    $cacheDir  = uploads_fs('_tile_cache/' . $z . '/' . $x);
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $y . '.png';
    $ttl       = 30 * 24 * 3600; // 30 dní

    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        // Cache hit — načteme z disku, bez síťového požadavku a bez usleep
        $data = @file_get_contents($cacheFile);
        if ($data !== false && strlen($data) >= 100) {
            $img = @imagecreatefromstring($data);
            return $img ?: null;
        }
        // Poškozený cache soubor — smažeme a pokračujeme na fetch
        @unlink($cacheFile);
    }

    // --- Cache miss: stáhneme z OSM ---
    $servers = ['a', 'b', 'c'];
    $s       = $servers[($x + $y) % 3];
    $url     = "https://{$s}.tile.openstreetmap.org/{$z}/{$x}/{$y}.png";

    $ctx = stream_context_create([
        'http' => [
            'timeout'       => 10,
            'user_agent'    => 'GPXManager/1.0 (thumbnail generator)',
            'ignore_errors' => true,
        ]
    ]);

    $data = @file_get_contents($url, false, $ctx);
    if ($data === false || strlen($data) < 100) return null;

    // Uložíme do cache
    @file_put_contents($cacheFile, $data);

    // Respektujeme OSM rate limit — 50ms pauza jen při skutečném network callu
    usleep(50000);

    $img = @imagecreatefromstring($data);
    return $img ?: null;
}

/**
 * Vypočítá optimální zoom pro daný rozsah souřadnic
 */
function best_zoom(float $minLat, float $maxLat, float $minLon, float $maxLon, int $targetW, int $targetH): int {
    for ($zoom = 16; $zoom >= 8; $zoom--) {
        [$tx1, $ty1] = coords_to_tile($maxLat, $minLon, $zoom);
        [$tx2, $ty2] = coords_to_tile($minLat, $maxLon, $zoom);
        $tilesW = abs($tx2 - $tx1) + 1;
        $tilesH = abs($ty2 - $ty1) + 1;
        // Max 4×4 dlaždice (šetří požadavky na OSM)
        if ($tilesW <= 4 && $tilesH <= 4) return $zoom;
    }
    return 8;
}

/**
 * Hlavní funkce — vygeneruje náhled trasy a uloží jako PNG
 *
 * @param string $gpxPath    Cesta k GPX souboru
 * @param string $thumbPath  Kam uložit výsledný PNG
 * @param int    $width      Šířka náhledu v px
 * @param int    $height     Výška náhledu v px
 * @return bool  true = úspěch, false = chyba
 */
function generate_thumb(string $gpxPath, string $thumbPath, int $width = 240, int $height = 120): bool {
    // --- Kontrola GD ---
    if (!function_exists('imagecreatetruecolor')) {
        error_log('generate_thumb: GD knihovna není dostupná');
        return false;
    }

    // --- Načtení GPX ---
    if (!is_file($gpxPath)) return false;
    $xml = safe_load_gpx($gpxPath);
    if (!$xml) return false;

    $xml->registerXPathNamespace('g', 'http://www.topografix.com/GPX/1/1');
    $trkpts = $xml->xpath('//g:trk/g:trkseg/g:trkpt');
    if (!$trkpts || count($trkpts) < 2) return false;

    // --- Sbírání souřadnic ---
    $lats = [];
    $lons = [];
    foreach ($trkpts as $pt) {
        $lats[] = (float)$pt['lat'];
        $lons[] = (float)$pt['lon'];
    }

    $minLat = min($lats); $maxLat = max($lats);
    $minLon = min($lons); $maxLon = max($lons);

    // Malé rozšíření okrajů (padding v souřadnicích)
    $padLat = ($maxLat - $minLat) * 0.12 ?: 0.001;
    $padLon = ($maxLon - $minLon) * 0.12 ?: 0.001;
    $minLat -= $padLat; $maxLat += $padLat;
    $minLon -= $padLon; $maxLon += $padLon;

    // --- Výpočet zoomu ---
    $zoom = best_zoom($minLat, $maxLat, $minLon, $maxLon, $width, $height);

    // --- Rozsah dlaždic ---
    [$tx1, $ty1] = coords_to_tile($maxLat, $minLon, $zoom);
    [$tx2, $ty2] = coords_to_tile($minLat, $maxLon, $zoom);
    if ($tx1 > $tx2) [$tx1, $tx2] = [$tx2, $tx1];
    if ($ty1 > $ty2) [$ty1, $ty2] = [$ty2, $ty1];

    // Omezení počtu dlaždic
    $tx2 = min($tx2, $tx1 + 3);
    $ty2 = min($ty2, $ty1 + 3);

    // --- Sestavení podkladové mapy z dlaždic ---
    $mapW = ($tx2 - $tx1 + 1) * 256;
    $mapH = ($ty2 - $ty1 + 1) * 256;
    $mapImg = imagecreatetruecolor($mapW, $mapH);

    // Světle šedé pozadí jako fallback
    $bgColor = imagecolorallocate($mapImg, 240, 240, 240);
    imagefill($mapImg, 0, 0, $bgColor);

    // Stažení a složení dlaždic
    for ($tx = $tx1; $tx <= $tx2; $tx++) {
        for ($ty = $ty1; $ty <= $ty2; $ty++) {
            $tile = fetch_tile($zoom, $tx, $ty);
            if ($tile) {
                $destX = ($tx - $tx1) * 256;
                $destY = ($ty - $ty1) * 256;
                imagecopy($mapImg, $tile, $destX, $destY, 0, 0, 256, 256);
                imagedestroy($tile);
            }
            // usleep je nyní součástí fetch_tile() — volá se jen při cache miss (síťový request)
        }
    }

    // --- Subsampling trackpointů (max 500 bodů pro výkon) ---
    $total = count($lats);
    $step  = max(1, (int)ceil($total / 500));
    $sampledLats = [];
    $sampledLons = [];
    for ($i = 0; $i < $total; $i += $step) {
        $sampledLats[] = $lats[$i];
        $sampledLons[] = $lons[$i];
    }
    // Vždy přidat poslední bod
    if ($sampledLats[count($sampledLats)-1] !== $lats[$total-1]) {
        $sampledLats[] = $lats[$total-1];
        $sampledLons[] = $lons[$total-1];
    }

    // --- Kreslení trasy na mapu ---
    $lineColor  = imagecolorallocatealpha($mapImg, 220, 50, 50, 0);   // červená
    $glowColor  = imagecolorallocatealpha($mapImg, 220, 50, 50, 80);  // průhledná pro záři

    imagesetthickness($mapImg, 1);

    // Záře (širší průhledná linka pod hlavní)
    for ($i = 1; $i < count($sampledLats); $i++) {
        [$px1, $py1] = coords_to_pixel($sampledLats[$i-1], $sampledLons[$i-1], $zoom, $tx1, $ty1);
        [$px2, $py2] = coords_to_pixel($sampledLats[$i],   $sampledLons[$i],   $zoom, $tx1, $ty1);
        imagesetthickness($mapImg, 5);
        imageline($mapImg, $px1, $py1, $px2, $py2, $glowColor);
    }

    // Hlavní linka
    imagesetthickness($mapImg, 3);
    for ($i = 1; $i < count($sampledLats); $i++) {
        [$px1, $py1] = coords_to_pixel($sampledLats[$i-1], $sampledLons[$i-1], $zoom, $tx1, $ty1);
        [$px2, $py2] = coords_to_pixel($sampledLats[$i],   $sampledLons[$i],   $zoom, $tx1, $ty1);
        imageline($mapImg, $px1, $py1, $px2, $py2, $lineColor);
    }

    // Start bod (zelený kruh)
    [$sx, $sy] = coords_to_pixel($sampledLats[0], $sampledLons[0], $zoom, $tx1, $ty1);
    $startColor = imagecolorallocate($mapImg, 0, 180, 0);
    imagefilledellipse($mapImg, $sx, $sy, 10, 10, $startColor);

    // Konec bod (červený kruh)
    $last = count($sampledLats) - 1;
    [$ex, $ey] = coords_to_pixel($sampledLats[$last], $sampledLons[$last], $zoom, $tx1, $ty1);
    $endColor = imagecolorallocate($mapImg, 200, 0, 0);
    imagefilledellipse($mapImg, $ex, $ey, 10, 10, $endColor);

    // --- Ořez a resize na cílové rozměry ---
    $thumbImg = imagecreatetruecolor($width, $height);
    imagecopyresampled($thumbImg, $mapImg, 0, 0, 0, 0, $width, $height, $mapW, $mapH);
    imagedestroy($mapImg);

    // --- Uložení ---
    $thumbDir = dirname($thumbPath);
    if (!is_dir($thumbDir)) @mkdir($thumbDir, 0755, true);

    $result = imagepng($thumbImg, $thumbPath, 8); // komprese 0-9
    imagedestroy($thumbImg);

    return $result;
}