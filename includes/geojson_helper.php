<?php
declare(strict_types=1);

/**
 * ===========================================================
 *  geojson_helper.php — track → GeoJSON (RFC 7946)
 *
 *  Shared by export_geojson.php (download of one track) and
 *  the GIS API (api/qgis/*). Coordinates are WGS 84 [lon, lat, ele].
 *  A track with more <trkseg> becomes a MultiLineString, so no
 *  straight line is drawn across a gap between segments.
 * ===========================================================
 */
require_once __DIR__ . '/gpx_parser.php';

/**
 * Builds a GeoJSON Feature (geometry + export properties) for a track row.
 *
 * $trackRow needs at least 'filename'; the properties come from
 * track_geojson_properties() and callers may replace them.
 *
 * @param array       $trackRow    Row from `tracks`.
 * @param bool        $withZ       Include elevation as the third coordinate.
 * @param int         $eleDecimals Rounding of elevation (lon/lat are always 7).
 * @param string|null $error       Set to 'bad_filename' | 'file_missing' | 'parse_error' | 'no_points' on failure.
 * @return array|null Feature, or null when the GPX can't be read.
 */
function track_geojson_feature(array $trackRow, bool $withZ = true, int $eleDecimals = 1, ?string &$error = null): ?array
{
    $error = null;
    $geometry = track_geojson_geometry((string)($trackRow['filename'] ?? ''), $withZ, $eleDecimals, $error);
    if ($geometry === null) {
        return null;
    }

    return [
        'type'       => 'Feature',
        'properties' => track_geojson_properties($trackRow),
        'geometry'   => $geometry,
    ];
}

/**
 * Reads the GPX file (relative to uploads/) and returns a LineString or
 * MultiLineString geometry, or null (see $error).
 *
 * $forceMulti: always MultiLineString — a GIS layer needs one geometry type;
 * mixed LineString/MultiLineString is read by GDAL/QGIS as "Unknown".
 * $simplifyM: Douglas–Peucker tolerance in metres (0 = full detail).
 */
function track_geojson_geometry(string $filename, bool $withZ = true, int $eleDecimals = 1, ?string &$error = null,
                                bool $forceMulti = false, float $simplifyM = 0.0): ?array
{
    $error = null;

    // filename comes from the DB, but never trust it for a filesystem path
    if ($filename === '' || strpos($filename, "\0") !== false
        || preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $filename)
        || preg_match('#^([\\\\/]|[A-Za-z]:)#', $filename)) {
        $error = 'bad_filename';
        return null;
    }
    try {
        $path = uploads_fs($filename);
    } catch (\RuntimeException $e) {
        $error = 'bad_filename';
        return null;
    }
    if (!is_file($path)) {
        $error = 'file_missing';
        return null;
    }

    $gpx = safe_load_gpx($path);
    if (!$gpx) {
        $error = 'parse_error';
        return null;
    }

    // local-name() works for GPX 1.0, 1.1 and files without a namespace
    $segments = $gpx->xpath("//*[local-name()='trk']/*[local-name()='trkseg']") ?: [];
    $lines    = [];
    $hasEle   = false;
    foreach ($segments as $seg) {
        $line = [];
        foreach ($seg->xpath("*[local-name()='trkpt']") ?: [] as $pt) {
            $ele = null;
            foreach ($pt->children($pt->getNamespaces()[''] ?? '') as $child) {
                if ($child->getName() === 'ele' && trim((string)$child) !== '') {
                    $ele = (float)$child;
                    break;
                }
            }
            if ($ele !== null) $hasEle = true;
            $line[] = [round((float)$pt['lon'], 7), round((float)$pt['lat'], 7), $ele];
        }
        if ($simplifyM > 0 && count($line) > 2) {
            $line = geojson_simplify_line($line, $simplifyM);
        }
        // A LineString needs at least two positions
        if (count($line) >= 2) {
            $lines[] = $line;
        }
    }

    if (!$lines) {
        $error = 'no_points';
        return null;
    }

    // Same dimension for every position: Z only when the track has elevation
    $withZ = $withZ && $hasEle;
    foreach ($lines as &$line) {
        foreach ($line as &$p) {
            if ($withZ) {
                $p[2] = round($p[2] ?? 0.0, $eleDecimals);
            } else {
                unset($p[2]);
            }
        }
        unset($p);
    }
    unset($line);

    return (count($lines) === 1 && !$forceMulti)
        ? ['type' => 'LineString',      'coordinates' => $lines[0]]
        : ['type' => 'MultiLineString', 'coordinates' => $lines];
}

/**
 * Douglas–Peucker simplification of [lon, lat, …] positions with a tolerance
 * in metres (local equirectangular projection — fine for one track).
 * Iterative, so long tracks don't hit the recursion limit. Keeps the
 * remaining positions unchanged (incl. elevation).
 */
function geojson_simplify_line(array $line, float $toleranceM): array
{
    $n = count($line);
    if ($n < 3 || $toleranceM <= 0) {
        return $line;
    }
    $lat0 = deg2rad((float)$line[intdiv($n, 2)][1]);
    $kx   = 111320.0 * cos($lat0);
    $ky   = 110540.0;
    $xy   = [];
    foreach ($line as $p) {
        $xy[] = [(float)$p[0] * $kx, (float)$p[1] * $ky];
    }

    $keep = array_fill(0, $n, false);
    $keep[0] = $keep[$n - 1] = true;
    $stack = [[0, $n - 1]];
    $tol2  = $toleranceM * $toleranceM;
    while ($stack) {
        [$a, $b] = array_pop($stack);
        [$ax, $ay] = $xy[$a];
        [$bx, $by] = $xy[$b];
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $len2 = $dx * $dx + $dy * $dy;
        $maxD = -1.0;
        $idx  = -1;
        for ($i = $a + 1; $i < $b; $i++) {
            [$px, $py] = $xy[$i];
            if ($len2 == 0.0) {
                $d = ($px - $ax) ** 2 + ($py - $ay) ** 2;
            } else {
                $t = max(0.0, min(1.0, (($px - $ax) * $dx + ($py - $ay) * $dy) / $len2));
                $d = ($px - $ax - $t * $dx) ** 2 + ($py - $ay - $t * $dy) ** 2;
            }
            if ($d > $maxD) {
                $maxD = $d;
                $idx  = $i;
            }
        }
        if ($idx > 0 && $maxD > $tol2) {
            $keep[$idx] = true;
            $stack[] = [$a, $idx];
            $stack[] = [$idx, $b];
        }
    }

    $out = [];
    foreach ($line as $i => $p) {
        if ($keep[$i]) $out[] = $p;
    }
    return $out;
}

/**
 * Properties of the exported track (export_geojson.php).
 * The GIS API uses the richer list item from gis_track_items() instead.
 */
function track_geojson_properties(array $trackRow): array
{
    $num = static fn($v, bool $int = false, int $dec = 3) =>
        $v === null ? null : ($int ? (int)$v : round((float)$v, $dec));

    return [
        // Export has always ignored alt_title — kept so its output stays the same
        'name'        => ($trackRow['track_name'] ?? '') ?: pathinfo((string)($trackRow['filename'] ?? ''), PATHINFO_FILENAME),
        'distance_km' => $num($trackRow['distance_km'] ?? null),
        'ascent_m'    => $num($trackRow['ascent'] ?? null, true),
        'descent_m'   => $num($trackRow['descent'] ?? null, true),
        'ele_min_m'   => $num($trackRow['elevation_min'] ?? null, true),
        'ele_max_m'   => $num($trackRow['elevation_max'] ?? null, true),
        'duration_s'  => $num($trackRow['duration'] ?? null, true),
        'date'        => !empty($trackRow['date_start']) ? substr((string)$trackRow['date_start'], 0, 10) : null,
        'activity'    => $trackRow['activity_type'] ?? null,
        'difficulty'  => $num($trackRow['difficulty'] ?? null, true),
    ];
}

/** Track name for GIS: alt_title ?: track_name ?: file name without extension. */
function track_geojson_name(array $trackRow): string
{
    foreach (['alt_title', 'track_name'] as $k) {
        $v = trim((string)($trackRow[$k] ?? ''));
        if ($v !== '') return $v;
    }
    return pathinfo((string)($trackRow['filename'] ?? ''), PATHINFO_FILENAME);
}

/**
 * Absolute URL of a path inside the app (GIS clients have no "current page").
 *
 * Base: app_config 'public_base_url' when set; otherwise scheme + HTTP_HOST
 * + app directory. Scheme is https when the request came over TLS or
 * APP_ENV is production. X-Forwarded-* headers are never read (spoofable).
 *
 * @param array|null  $server  For tests; null = $_SERVER.
 * @param string|null $baseCfg For tests; null = app_config 'public_base_url'.
 * @param string|null $env     For tests; null = APP_ENV.
 */
function gis_absolute_url(string $relative, ?array $server = null, ?string $baseCfg = null, ?string $env = null): string
{
    if (preg_match('#^https?://#i', $relative)) {
        return $relative;
    }

    $server  = $server ?? $_SERVER;
    $baseCfg = $baseCfg ?? (function_exists('get_app_config') ? trim((string)get_app_config('public_base_url', '')) : '');
    $env     = $env ?? (defined('APP_ENV') ? APP_ENV : 'production');

    if ($baseCfg !== '') {
        $base = rtrim($baseCfg, '/');
    } else {
        $https  = !empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off';
        $scheme = ($https || $env === 'production') ? 'https' : 'http';

        $host = (string)($server['HTTP_HOST'] ?? '');
        if (!preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$|^\[[0-9A-Fa-f:.]+\](:\d{1,5})?$/', $host)) {
            $host = (string)($server['SERVER_NAME'] ?? 'localhost');
        }

        $base = $scheme . '://' . $host . gis_app_base_path($server);
    }

    return $base . '/' . ltrim($relative, '/');
}

/**
 * URL path of the app root without a trailing slash ('' when the app is
 * the document root). Derived from SCRIPT_NAME minus the script's path
 * inside the app, so it works from any depth (api/qgis/x.php).
 */
function gis_app_base_path(array $server): string
{
    $scriptName = str_replace('\\', '/', (string)($server['SCRIPT_NAME'] ?? ''));
    $scriptFile = (string)($server['SCRIPT_FILENAME'] ?? '');
    $appRoot    = realpath(__DIR__ . '/..');

    $realFile = $scriptFile !== '' ? realpath($scriptFile) : false;
    if ($appRoot !== false && $realFile !== false && strpos($realFile, $appRoot) === 0) {
        $inside = str_replace('\\', '/', substr($realFile, strlen($appRoot)));   // e.g. /api/qgis/ping.php
        $len    = strlen($inside);
        if ($len > 0 && substr($scriptName, -$len) === $inside) {
            return rtrim(substr($scriptName, 0, -$len), '/');
        }
    }

    // Fallback: directory of the entry script (correct for root-level pages)
    // (dirname() returns "\" for "/x.php" on Windows)
    $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    return $dir === '.' ? '' : $dir;
}
