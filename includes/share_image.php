<?php
declare(strict_types=1);

/**
 * ===========================================================
 *  GPX Manager – Obrázek trasy pro náhled sdíleného odkazu
 *  1200×630 px (Open Graph), mapa z OSM dlaždic + linie trasy.
 *
 *  Proč ne generate_thumb() s jinými rozměry: ten složí mozaiku
 *  dlaždic a přeškáluje ji na cílový rozměr, takže mapu natáhne.
 *  U náhledu 240×120 to nevadilo, u obrázku do diskuse ano.
 *
 *  Zoom je po celých číslech, takže trasa buď zabírala půlku
 *  obrázku, nebo se mačkala na okraj. Kreslí se proto do většího
 *  okna a to se pak zmenší na cílový rozměr — tím vznikne
 *  „mezizoom“ a navíc je výsledek hladší (supersampling).
 *
 *  Dlaždice bere přes fetch_tile() z generate_thumb.php (sdílená
 *  disková cache, respektovaný rate limit OSM).
 * ===========================================================
 */
require_once __DIR__ . '/generate_thumb.php';

/** Zeměpisná délka → pixel v globální mapě daného zoomu. */
function share_px_x(float $lon, int $zoom): float {
    return ($lon + 180.0) / 360.0 * 256.0 * pow(2, $zoom);
}

/** Zeměpisná šířka → pixel v globální mapě daného zoomu (roste k jihu). */
function share_px_y(float $lat, int $zoom): float {
    $r = deg2rad($lat);
    return (1.0 - log(tan($r) + 1.0 / cos($r)) / M_PI) / 2.0 * 256.0 * pow(2, $zoom);
}

/**
 * Vygeneruje sdílecí obrázek trasy (JPEG).
 *
 * @param string $gpxPath  Cesta ke GPX souboru
 * @param string $outPath  Kam uložit JPEG
 * @param int    $W        Šířka (výchozí 1200 — doporučení Open Graph)
 * @param int    $H        Výška (výchozí 630)
 * @return bool
 */
function generate_share_image(string $gpxPath, string $outPath, int $W = 1200, int $H = 630): bool {
    if (!function_exists('imagecreatetruecolor')) {
        error_log('generate_share_image: GD není dostupné');
        return false;
    }
    if (!is_file($gpxPath)) return false;

    $xml = safe_load_gpx($gpxPath);
    if (!$xml) return false;
    $xml->registerXPathNamespace('g', 'http://www.topografix.com/GPX/1/1');
    $trkpts = $xml->xpath('//g:trk/g:trkseg/g:trkpt');
    if (!$trkpts || count($trkpts) < 2) return false;

    $lats = [];
    $lons = [];
    foreach ($trkpts as $pt) {
        $lats[] = (float)$pt['lat'];
        $lons[] = (float)$pt['lon'];
    }

    $minLat = min($lats); $maxLat = max($lats);
    $minLon = min($lons); $maxLon = max($lons);

    // Podíl plochy, který smí trasa zabrat — zbytek je vzduch kolem
    $FILL      = 0.86;
    // Kolik smí být okno větší než cílový obrázek. Strop drží počet
    // stažených dlaždic v rozumných mezích (1.4 → nejvýš ~40 dlaždic).
    $SCALE_MAX = 1.4;

    // Nejvyšší zoom, u kterého se trasa do okna vejde. Když je při něm
    // trasa větší než cílový obrázek, okno se zvětší a nakonec zmenší.
    $zoom = 3; $scale = 1.0;
    for ($z = 17; $z >= 3; $z--) {
        $w = share_px_x($maxLon, $z) - share_px_x($minLon, $z);
        $h = share_px_y($minLat, $z) - share_px_y($maxLat, $z);
        $s = max($w / ($W * $FILL), $h / ($H * $FILL));
        if ($s <= $SCALE_MAX) { $zoom = $z; $scale = max(1.0, $s); break; }
    }

    $WW = (int)ceil($W * $scale);
    $HH = (int)ceil($H * $scale);

    // --- Okno v pixelech mapy, vycentrované na trasu ---
    $cx = (share_px_x($minLon, $zoom) + share_px_x($maxLon, $zoom)) / 2.0;
    $cy = (share_px_y($minLat, $zoom) + share_px_y($maxLat, $zoom)) / 2.0;
    $left = $cx - $WW / 2.0;
    $top  = $cy - $HH / 2.0;

    $tx1 = (int)floor($left / 256);
    $ty1 = (int)floor($top / 256);
    $tx2 = (int)floor(($left + $WW - 1) / 256);
    $ty2 = (int)floor(($top  + $HH - 1) / 256);

    $mapW = ($tx2 - $tx1 + 1) * 256;
    $mapH = ($ty2 - $ty1 + 1) * 256;
    $mapImg = imagecreatetruecolor($mapW, $mapH);
    imagefill($mapImg, 0, 0, imagecolorallocate($mapImg, 240, 240, 240));

    for ($tx = $tx1; $tx <= $tx2; $tx++) {
        for ($ty = $ty1; $ty <= $ty2; $ty++) {
            $tile = fetch_tile($zoom, $tx, $ty);
            if ($tile) {
                imagecopy($mapImg, $tile, ($tx - $tx1) * 256, ($ty - $ty1) * 256, 0, 0, 256, 256);
                imagedestroy($tile);
            }
        }
    }

    // --- Výřez okna + linie trasy (kreslí se v měřítku okna) ---
    $win = imagecreatetruecolor($WW, $HH);
    imagecopy($win, $mapImg, 0, 0, (int)round($left - $tx1 * 256), (int)round($top - $ty1 * 256), $WW, $HH);
    imagedestroy($mapImg);

    // Víc než 800 bodů se na 1200 px šířky stejně neprojeví
    $total = count($lats);
    $step  = max(1, (int)ceil($total / 800));
    $pts = [];
    for ($i = 0; $i < $total; $i += $step) {
        $pts[] = [share_px_x($lons[$i], $zoom) - $left, share_px_y($lats[$i], $zoom) - $top];
    }
    $pts[] = [share_px_x($lons[$total - 1], $zoom) - $left, share_px_y($lats[$total - 1], $zoom) - $top];

    $glow = imagecolorallocatealpha($win, 220, 50, 50, 85);
    $line = imagecolorallocate($win, 214, 40, 40);

    imagesetthickness($win, (int)max(1, round(9 * $scale)));
    for ($i = 1; $i < count($pts); $i++) {
        imageline($win, (int)$pts[$i-1][0], (int)$pts[$i-1][1], (int)$pts[$i][0], (int)$pts[$i][1], $glow);
    }
    imagesetthickness($win, (int)max(1, round(5 * $scale)));
    for ($i = 1; $i < count($pts); $i++) {
        imageline($win, (int)$pts[$i-1][0], (int)$pts[$i-1][1], (int)$pts[$i][0], (int)$pts[$i][1], $line);
    }

    // --- Zmenšení na cílový rozměr ---
    if ($WW !== $W || $HH !== $H) {
        $img = imagecreatetruecolor($W, $H);
        imagecopyresampled($img, $win, 0, 0, 0, 0, $W, $H, $WW, $HH);
        imagedestroy($win);
    } else {
        $img = $win;
    }

    // Značky a popisek až po zmenšení, ať jsou ostré
    $white = imagecolorallocate($img, 255, 255, 255);
    $green = imagecolorallocate($img, 0, 150, 0);
    $red   = imagecolorallocate($img, 190, 0, 0);
    $sx = (int)round($pts[0][0] / $scale);
    $sy = (int)round($pts[0][1] / $scale);
    $ex = (int)round($pts[count($pts) - 1][0] / $scale);
    $ey = (int)round($pts[count($pts) - 1][1] / $scale);
    imagefilledellipse($img, $sx, $sy, 22, 22, $white);
    imagefilledellipse($img, $sx, $sy, 16, 16, $green);
    imagefilledellipse($img, $ex, $ey, 22, 22, $white);
    imagefilledellipse($img, $ex, $ey, 16, 16, $red);

    // --- Povinná atribuce OSM (obrázek putuje na cizí servery) ---
    $txt  = '(c) OpenStreetMap';
    $fw   = imagefontwidth(5) * strlen($txt);
    $fh   = imagefontheight(5);
    $barW = $fw + 16;
    imagefilledrectangle($img, $W - $barW, $H - $fh - 10, $W, $H,
        imagecolorallocatealpha($img, 255, 255, 255, 40));
    imagestring($img, 5, $W - $barW + 8, $H - $fh - 5, $txt,
        imagecolorallocate($img, 40, 40, 40));

    $dir = dirname($outPath);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    // JPEG místo PNG: mapa je fotografická, PNG vycházelo přes 500 kB
    // a obrázek se posílá ven při každém náhledu odkazu.
    $ok = imagejpeg($img, $outPath, 85);
    imagedestroy($img);
    return $ok;
}
