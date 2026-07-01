<?php
declare(strict_types=1);

/**
 * GPX Parser – kompatibilní s Garmin GPX (gpxx/gpxtpx) i "holými" GPX bez extensions.
 * Vrací asociativní pole vhodné pro INSERT/UPDATE do tabulky `tracks`.
 */

/**
 * Bezpečné načtení GPX souboru — blokuje XXE/DTD útoky.
 *
 * Odmítne soubory obsahující <!DOCTYPE nebo <!ENTITY, aby zabránil
 * XXE (XML External Entity) útokům (SEC-001). LIBXML_NONET zabraňuje
 * síťovým požadavkům z XML parseru i v případě, že by pre-check selhal.
 */
function safe_load_gpx(string $filePath): ?SimpleXMLElement
{
    if (!is_readable($filePath)) {
        error_log("safe_load_gpx: not readable: $filePath");
        return null;
    }
    $head = @file_get_contents($filePath, false, null, 0, 4096);
    if ($head === false) {
        error_log("safe_load_gpx: head read failed: $filePath");
        return null;
    }
    if (stripos($head, '<!DOCTYPE') !== false || stripos($head, '<!ENTITY') !== false) {
        error_log("safe_load_gpx: refusing DTD/ENTITY in $filePath");
        return null;
    }
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($filePath, 'SimpleXMLElement', LIBXML_NONET);
    return $xml !== false ? $xml : null;
}

/**
 * Bezpečné parsování GPX z řetězce — pro upload validation,
 * než se obsah uloží na disk. Stejné kontroly jako safe_load_gpx().
 */
function safe_load_gpx_string(string $xmlContent): ?SimpleXMLElement
{
    if ($xmlContent === '') return null;
    // Pre-check pouze prvních 4 KB — DOCTYPE/ENTITY musí být v prologu
    $head = substr($xmlContent, 0, 4096);
    if (stripos($head, '<!DOCTYPE') !== false || stripos($head, '<!ENTITY') !== false) {
        error_log("safe_load_gpx_string: refusing DTD/ENTITY content");
        return null;
    }
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NONET);
    return $xml !== false ? $xml : null;
}

function parse_gpx(string $filePath): ?array
{
    if (!is_file($filePath)) return null;

    $xml = safe_load_gpx($filePath);
    if (!$xml) return null;

    // Register namespaces once — shared by all helpers via the same $xml object
    $ns = _gpx_namespaces();
    foreach ($ns as $prefix => $uri) {
        $xml->registerXPathNamespace($prefix, $uri);
    }

    // --- Basic track metadata ---
    $device = isset($xml['creator']) ? trim((string)$xml['creator']) : null;

    $trackName = '';
    foreach ($xml->xpath('//g:trk/g:name') ?: [] as $n) {
        $t = trim((string)$n);
        if ($t !== '') { $trackName = $t; break; }
    }
    if ($trackName === '') {
        $trackName = basename($filePath);
    }

    $color  = null;
    $cNodes = $xml->xpath('//g:trk/g:extensions/gpxx:TrackExtension/gpxx:DisplayColor');
    if ($cNodes && isset($cNodes[0])) {
        $color = trim((string)$cNodes[0]) ?: null;
    }

    // --- Extract, compute, apply ---
    $trackpoints = _gpx_extract_trackpoints($xml, $ns);
    if (empty($trackpoints)) return null;

    $metrics = _gpx_compute_metrics($trackpoints);
    $metrics = _gpx_apply_trackstats($xml, $ns, $metrics);
    $bounds  = _gpx_compute_bounds($trackpoints);

    // --- Derive averaged speeds once, after TrackStatsExtension overrides ---
    $avgSpeedMS_moving = ($metrics['movingTimeS'] > 0)
        ? ($metrics['totalDistM'] / $metrics['movingTimeS']) : 0.0;
    $avgSpeedMS_total  = ($metrics['durationS']   > 0)
        ? ($metrics['totalDistM'] / $metrics['durationS'])   : 0.0;

    return [
        'track_name'        => $trackName ?: null,
        'color'             => $color,
        'device'            => ($device !== '' && $device !== null) ? $device : null,
        'date_start'        => $metrics['timeStart'] ? date('Y-m-d H:i:s', $metrics['timeStart']) : null,
        'date_end'          => $metrics['timeEnd']   ? date('Y-m-d H:i:s', $metrics['timeEnd'])   : null,
        'duration'          => $metrics['durationS']   ?: null,
        'moving_time'       => $metrics['movingTimeS'] ?: null,
        'stopped_time'      => $metrics['stoppedTimeS'] ?: null,
        'distance_km'       => round($metrics['totalDistM'] / 1000, 3),
        'ascent'            => round($metrics['ascentM'], 1),
        'descent'           => round($metrics['descentM'], 1),
        'elevation_min'     => is_finite($metrics['eleMin']) ? round($metrics['eleMin'], 1) : null,
        'elevation_max'     => is_finite($metrics['eleMax']) ? round($metrics['eleMax'], 1) : null,
        'speed_max'         => round($metrics['speedMaxMS'] * 3.6, 3),
        'speed_avg'         => round($avgSpeedMS_moving * 3.6, 3),
        'speed_avg_total'   => round($avgSpeedMS_total  * 3.6, 3),
        'avg_ascent_rate'   => $metrics['avgAscentRate'],
        'avg_descent_rate'  => $metrics['avgDescentRate'],
        'max_ascent_rate'   => $metrics['maxAscentRate'],
        'max_descent_rate'  => $metrics['maxDescentRate'],
        'bounds'            => $bounds,
        'trackpoints_count' => count($trackpoints),
    ];
}

// ---------------------------------------------------------------------------
// Private helpers — prefixed with _gpx_ to indicate internal use
// ---------------------------------------------------------------------------

/**
 * Returns the namespace URI map used across all GPX helpers.
 *
 * @return array<string,string>
 */
function _gpx_namespaces(): array
{
    return [
        'g'       => 'http://www.topografix.com/GPX/1/1',
        'gpxx'    => 'http://www.garmin.com/xmlschemas/GpxExtensions/v3',
        'gpxtrkx' => 'http://www.garmin.com/xmlschemas/TrackStatsExtension/v1',
        'tp1'     => 'http://www.garmin.com/xmlschemas/TrackPointExtension/v1',
        'tp2'     => 'http://www.garmin.com/xmlschemas/TrackPointExtension/v2',
    ];
}

/**
 * Extracts raw trackpoints from the GPX XML.
 *
 * Each element: ['lat'=>float, 'lon'=>float, 'ele'=>?float, 'time'=>?int, 'speed'=>?float]
 *
 * @param SimpleXMLElement    $xml Parsed GPX root
 * @param array<string,string> $ns  Namespace URI map (keyed by prefix)
 * @return list<array{lat:float,lon:float,ele:float|null,time:int|null,speed:float|null}>
 */
function _gpx_extract_trackpoints(SimpleXMLElement $xml, array $ns): array
{
    $trkpts = $xml->xpath('//g:trk/g:trkseg/g:trkpt');
    if (!$trkpts || count($trkpts) === 0) return [];

    $nsTPXv1 = $ns['tp1'];
    $nsTPXv2 = $ns['tp2'];

    // Ploché indexy bodů, které jsou prvním bodem nového trkseg. Vzdálenost se
    // přes ně NEpřičítá (mezera po odfiltrování / pauza záznamu → žádná přímka přes díru).
    $segStart = _gpx_segment_start_indices($xml, $ns);

    $points = [];
    foreach ($trkpts as $i => $pt) {
        $lat  = (float)$pt['lat'];
        $lon  = (float)$pt['lon'];
        $ele  = isset($pt->ele)  ? (float)$pt->ele  : null;
        $time = isset($pt->time) ? strtotime((string)$pt->time) : null;

        $speed = null;
        if (isset($pt->extensions)) {
            $ext2 = $pt->extensions->children($nsTPXv2);
            if ($ext2 && isset($ext2->TrackPointExtension->speed)) {
                $speed = (float)$ext2->TrackPointExtension->speed;
            } else {
                $ext1 = $pt->extensions->children($nsTPXv1);
                if ($ext1 && isset($ext1->TrackPointExtension->speed)) {
                    $speed = (float)$ext1->TrackPointExtension->speed;
                }
            }
        }

        $point = ['lat' => $lat, 'lon' => $lon, 'ele' => $ele, 'time' => $time, 'speed' => $speed];
        if (isset($segStart[$i])) {
            $point['seg_start'] = true;
        }
        $points[] = $point;
    }

    return $points;
}

/**
 * Vrátí množinu plochých indexů bodů (napříč všemi trkseg), které jsou PRVNÍM
 * bodem nového segmentu. Slouží k tomu, aby se vzdálenost/převýšení nepřičítaly
 * přes zlom mezi segmenty (mezera po odfiltrování úseku, pauza záznamu apod.).
 *
 * @return array<int,true>
 */
function _gpx_segment_start_indices(SimpleXMLElement $xml, array $ns): array
{
    $starts = [];
    $flat   = 0;
    $segs   = $xml->xpath('//g:trk/g:trkseg') ?: [];
    foreach ($segs as $i => $seg) {
        $cnt = count($seg->children($ns['g'])->trkpt);
        if ($i > 0 && $flat > 0 && $cnt > 0) {
            $starts[$flat] = true;   // první bod tohoto segmentu = zlom
        }
        $flat += $cnt;
    }
    return $starts;
}

/**
 * Computes distance/elevation/speed/time metrics from raw trackpoints.
 *
 * Uses MOVING_THRESHOLD_MS to classify stationary segments and
 * GPX_ELEVATION_NOISE_M as a hysteresis band for ascent/descent.
 * avgSpeedMS_moving / avgSpeedMS_total are NOT included here — they are
 * derived in parse_gpx() after _gpx_apply_trackstats() may update the times.
 *
 * @param list<array{lat:float,lon:float,ele:float|null,time:int|null,speed:float|null}> $trackpoints
 * @return array<string,mixed>
 */
function _gpx_compute_metrics(array $trackpoints): array
{
    $totalDistM  = 0.0;
    $ascentM     = 0.0;
    $descentM    = 0.0;
    $speedMaxMS  = 0.0;
    $movingTimeS = 0.0;
    $eleMin      = INF;
    $eleMax      = -INF;
    $eleRef      = null;
    $times       = [];

    $count = count($trackpoints);
    for ($i = 0; $i < $count; $i++) {
        $p = $trackpoints[$i];

        // Nový segment (zlom po mezeře / pauze) — nepřičítat převýšení ani
        // vzdálenost/rychlost přes díru (žádná přímka mezi konci úseků).
        $segBreak = !empty($p['seg_start']);
        if ($segBreak) $eleRef = null;

        if ($p['time'] !== null) $times[] = $p['time'];
        if ($p['ele'] !== null) {
            if ($p['ele'] < $eleMin) $eleMin = $p['ele'];
            if ($p['ele'] > $eleMax) $eleMax = $p['ele'];

            // Stoupání/klesání s hysterezí: změny menší než GPX_ELEVATION_NOISE_M
            // se považují za GPS šum a nezapočítají se. Trvalé převýšení se
            // akumuluje od poslední významné hodnoty ($eleRef).
            if ($eleRef === null) {
                $eleRef = $p['ele'];
            } else {
                $eleDelta = $p['ele'] - $eleRef;
                if ($eleDelta >= GPX_ELEVATION_NOISE_M) {
                    $ascentM += $eleDelta;
                    $eleRef = $p['ele'];
                } elseif ($eleDelta <= -GPX_ELEVATION_NOISE_M) {
                    $descentM += -$eleDelta;
                    $eleRef = $p['ele'];
                }
            }
        }

        if ($i === 0 || $segBreak) continue;

        $a = $trackpoints[$i - 1];
        $b = $p;

        $d = haversine($a['lat'], $a['lon'], $b['lat'], $b['lon']);
        $totalDistM += $d;

        // Instantaneous speed: prefer device-recorded value, fall back to derived
        $v = null;
        if ($a['time'] !== null && $b['time'] !== null && $b['time'] > $a['time']) {
            $dt = $b['time'] - $a['time'];
            $v  = $d / max(1, $dt);
        }
        if ($b['speed'] !== null) $v = max($v ?? 0.0, (float)$b['speed']);
        if ($v !== null && $v > $speedMaxMS) $speedMaxMS = $v;

        // Accumulate moving time
        if ($a['time'] !== null && $b['time'] !== null) {
            $dt   = $b['time'] - $a['time'];
            $vRef = ($b['speed'] !== null) ? (float)$b['speed'] : ($v ?? 0.0);
            if ($vRef > MOVING_THRESHOLD_MS) $movingTimeS += $dt;
        }
    }

    $timeStart    = !empty($times) ? min($times) : null;
    $timeEnd      = !empty($times) ? max($times) : null;
    $durationS    = ($timeStart !== null && $timeEnd !== null && $timeEnd >= $timeStart)
                    ? ($timeEnd - $timeStart) : 0;
    $stoppedTimeS = max(0, $durationS - $movingTimeS);

    return [
        'totalDistM'    => $totalDistM,
        'ascentM'       => $ascentM,
        'descentM'      => $descentM,
        'speedMaxMS'    => $speedMaxMS,
        'movingTimeS'   => $movingTimeS,
        'durationS'     => $durationS,
        'stoppedTimeS'  => $stoppedTimeS,
        'timeStart'     => $timeStart,
        'timeEnd'       => $timeEnd,
        'eleMin'        => $eleMin,
        'eleMax'        => $eleMax,
        // Rate fields — populated (or left null) by _gpx_apply_trackstats
        'avgAscentRate' => null,
        'avgDescentRate'=> null,
        'maxAscentRate' => null,
        'maxDescentRate'=> null,
    ];
}

/**
 * Applies Garmin TrackStatsExtension values on top of computed metrics.
 * Device-provided values are more accurate and override calculated ones.
 *
 * Returns the updated metrics array.
 *
 * @param SimpleXMLElement    $xml     Parsed GPX root (namespaces already registered)
 * @param array<string,string> $ns      Namespace URI map
 * @param array<string,mixed>  $metrics Output of _gpx_compute_metrics()
 * @return array<string,mixed>
 */
function _gpx_apply_trackstats(SimpleXMLElement $xml, array $ns, array $metrics): array
{
    $nsTRKX   = $ns['gpxtrkx'];
    $trkStats = $xml->xpath('//g:trk/g:extensions/gpxtrkx:TrackStatsExtension');
    if (!$trkStats || !isset($trkStats[0])) return $metrics;

    $st = $trkStats[0]->children($nsTRKX);

    if (isset($st->MaximumSpeed) && $st->MaximumSpeed !== '') {
        $metrics['speedMaxMS'] = max($metrics['speedMaxMS'], (float)$st->MaximumSpeed);
    }
    if (isset($st->TotalTime) && $st->TotalTime !== '') {
        $metrics['durationS']    = (int)$st->TotalTime;
        $metrics['stoppedTimeS'] = max(0, $metrics['durationS'] - $metrics['movingTimeS']);
    }
    if (isset($st->MovingTime) && $st->MovingTime !== '') {
        $metrics['movingTimeS']  = (int)$st->MovingTime;
        if ($metrics['durationS'] > 0) {
            $metrics['stoppedTimeS'] = max(0, $metrics['durationS'] - $metrics['movingTimeS']);
        }
    }
    if (isset($st->Ascent) && $st->Ascent !== '') {
        $metrics['ascentM'] = (float)$st->Ascent;
    }
    if (isset($st->Descent) && $st->Descent !== '') {
        $metrics['descentM'] = (float)$st->Descent;
    }
    if (isset($st->AvgAscentRate) && $st->AvgAscentRate !== '') {
        $metrics['avgAscentRate'] = (float)$st->AvgAscentRate;
    }
    if (isset($st->AvgDescentRate) && $st->AvgDescentRate !== '') {
        $metrics['avgDescentRate'] = (float)$st->AvgDescentRate;
    }
    if (isset($st->MaxAscentRate) && $st->MaxAscentRate !== '') {
        $metrics['maxAscentRate'] = (float)$st->MaxAscentRate;
    }
    if (isset($st->MaxDescentRate) && $st->MaxDescentRate !== '') {
        $metrics['maxDescentRate'] = (float)$st->MaxDescentRate;
    }

    return $metrics;
}

/**
 * Computes bounding box from trackpoints and returns it as a JSON string.
 * Returns null if the trackpoints array is empty.
 *
 * @param list<array{lat:float,lon:float,...}> $trackpoints
 * @return string|null JSON: {minlat, maxlat, minlon, maxlon}
 */
function _gpx_compute_bounds(array $trackpoints): ?string
{
    if (empty($trackpoints)) return null;

    $lats = array_column($trackpoints, 'lat');
    $lons = array_column($trackpoints, 'lon');

    return json_encode([
        'minlat' => min($lats),
        'maxlat' => max($lats),
        'minlon' => min($lons),
        'maxlon' => max($lons),
    ], JSON_UNESCAPED_SLASHES);
}

/**
 * Haversine vzdálenost v metrech
 */
function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $R    = 6371000.0;
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $dphi = $phi2 - $phi1;
    $dl   = deg2rad($lon2 - $lon1);

    $a = sin($dphi/2)**2 + cos($phi1)*cos($phi2)*sin($dl/2)**2;
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
}