<?php
/**
 * GPX Parser – kompatibilní s Garmin GPX (gpxx/gpxtpx) i "holými" GPX bez extensions.
 * Vrací asociativní pole vhodné pro INSERT/UPDATE do tabulky `tracks`.
 */

function parse_gpx(string $filePath): ?array
{
    if (!is_file($filePath)) return null;

    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($filePath);
    if (!$xml) return null;

    // --- Namespaces ---
    $nsGPX   = 'http://www.topografix.com/GPX/1/1';
    $nsGPXX  = 'http://www.garmin.com/xmlschemas/GpxExtensions/v3';
    $nsTRKX  = 'http://www.garmin.com/xmlschemas/TrackStatsExtension/v1';
    $nsTPXv1 = 'http://www.garmin.com/xmlschemas/TrackPointExtension/v1';
    $nsTPXv2 = 'http://www.garmin.com/xmlschemas/TrackPointExtension/v2';

    $xml->registerXPathNamespace('g',       $nsGPX);
    $xml->registerXPathNamespace('gpxx',    $nsGPXX);
    $xml->registerXPathNamespace('gpxtrkx', $nsTRKX);
    $xml->registerXPathNamespace('tp1',     $nsTPXv1);
    $xml->registerXPathNamespace('tp2',     $nsTPXv2);

    // --- Základní údaje ---
    $device = isset($xml['creator']) ? trim((string)$xml['creator']) : null;

    // Název trasy – vezmeme první neprázdný <trk><name>
    $trackName = '';
    foreach ($xml->xpath('//g:trk/g:name') ?: [] as $n) {
        $t = trim((string)$n);
        if ($t !== '') { $trackName = $t; break; }
    }
    if ($trackName === '') {
        $trackName = basename($filePath);
    }

    // Barva (pokud Garmin GPXX k dispozici)
    $color = null;
    $cNodes = $xml->xpath('//g:trk/g:extensions/gpxx:TrackExtension/gpxx:DisplayColor');
    if ($cNodes && isset($cNodes[0])) {
        $color = trim((string)$cNodes[0]) ?: null;
    }

    // --- Shromáždit trackpointy ---
    $trkpts = $xml->xpath('//g:trk/g:trkseg/g:trkpt');
    if (!$trkpts || count($trkpts) === 0) return null;

    $points = [];
    $eleMin = INF; $eleMax = -INF;

    foreach ($trkpts as $pt) {
        $lat   = (float)$pt['lat'];
        $lon   = (float)$pt['lon'];
        $ele   = isset($pt->ele)  ? (float)$pt->ele  : null;
        $time  = isset($pt->time) ? strtotime((string)$pt->time) : null;

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

        $points[] = ['lat'=>$lat,'lon'=>$lon,'ele'=>$ele,'time'=>$time,'speed'=>$speed];

        if ($ele !== null) {
            if ($ele < $eleMin) $eleMin = $ele;
            if ($ele > $eleMax) $eleMax = $ele;
        }
    }

    if (empty($points)) return null;
    $trackpointsCount = count($points);

    // --- Výpočet metrik z bodů ---
    $totalDistM   = 0.0;
    $ascentM      = 0.0;
    $descentM     = 0.0;
    $speedMaxMS   = 0.0;
    $movingTimeS  = 0.0;
    $MOVING_THRESHOLD = 0.5; // m/s
    $times = [];
    $lats  = [];
    $lons  = [];

    for ($i = 0; $i < $trackpointsCount; $i++) {
        $p = $points[$i];
        $lats[] = $p['lat'];
        $lons[] = $p['lon'];
        if ($p['time']) $times[] = $p['time'];

        if ($i === 0) continue;
        $a = $points[$i-1];
        $b = $p;

        $d = haversine($a['lat'], $a['lon'], $b['lat'], $b['lon']);
        $totalDistM += $d;

        if ($a['ele'] !== null && $b['ele'] !== null) {
            $diff = $b['ele'] - $a['ele'];
            if ($diff > 0) $ascentM += $diff;
            else $descentM += -$diff;
        }

        $v = null;
        if ($a['time'] && $b['time'] && ($b['time'] > $a['time'])) {
            $dt = $b['time'] - $a['time'];
            $v  = $d / max(1, $dt);
        }
        if ($b['speed'] !== null) $v = max($v ?? 0, (float)$b['speed']);
        if ($v !== null && $v > $speedMaxMS) $speedMaxMS = $v;

        if ($a['time'] && $b['time']) {
            $dt   = $b['time'] - $a['time'];
            $vRef = ($b['speed'] !== null) ? (float)$b['speed'] : ($v ?? 0.0);
            if ($vRef > $MOVING_THRESHOLD) $movingTimeS += $dt;
        }
    }

    // --- Časy ---
    $timeStart  = !empty($times) ? min($times) : null;
    $timeEnd    = !empty($times) ? max($times) : null;
    $durationS  = ($timeStart && $timeEnd && $timeEnd >= $timeStart)
                  ? ($timeEnd - $timeStart) : 0;
    $stoppedTimeS = max(0, $durationS - $movingTimeS);

    $avgSpeedMS_moving = ($movingTimeS > 0) ? ($totalDistM / $movingTimeS) : 0.0;
    $avgSpeedMS_total  = ($durationS   > 0) ? ($totalDistM / $durationS)   : 0.0;

    // --- TrackStatsExtension (přepíše dopočtené hodnoty pokud existuje) ---
    $avgAscentRate = null; $avgDescentRate = null;
    $maxAscentRate = null; $maxDescentRate = null;

    $trkStats = $xml->xpath('//g:trk/g:extensions/gpxtrkx:TrackStatsExtension');
    if ($trkStats && isset($trkStats[0])) {
        $st = $trkStats[0]->children($nsTRKX);

        if (isset($st->MaximumSpeed) && $st->MaximumSpeed !== '') {
            $speedMaxMS = max($speedMaxMS, (float)$st->MaximumSpeed);
        }
        if (isset($st->TotalTime) && $st->TotalTime !== '') {
            $durationS    = (int)$st->TotalTime;
            $stoppedTimeS = max(0, $durationS - $movingTimeS);
        }
        if (isset($st->MovingTime) && $st->MovingTime !== '') {
            $movingTimeS  = (int)$st->MovingTime;
            if ($durationS > 0) $stoppedTimeS = max(0, $durationS - $movingTimeS);
        }
        if (isset($st->Ascent) && $st->Ascent !== '') {
            $ascentM = (float)$st->Ascent;
        }
        if (isset($st->Descent) && $st->Descent !== '') {
            $descentM = (float)$st->Descent;
        }
        if (isset($st->AvgAscentRate) && $st->AvgAscentRate !== '') {
            $avgAscentRate = (float)$st->AvgAscentRate;
        }
        if (isset($st->AvgDescentRate) && $st->AvgDescentRate !== '') {
            $avgDescentRate = (float)$st->AvgDescentRate;
        }
        if (isset($st->MaxAscentRate) && $st->MaxAscentRate !== '') {
            $maxAscentRate = (float)$st->MaxAscentRate;
        }
        if (isset($st->MaxDescentRate) && $st->MaxDescentRate !== '') {
            $maxDescentRate = (float)$st->MaxDescentRate;
        }
    }

    // --- Bounds ---
    $bounds = json_encode([
        'minlat' => min($lats),
        'maxlat' => max($lats),
        'minlon' => min($lons),
        'maxlon' => max($lons),
    ], JSON_UNESCAPED_SLASHES);

    // --- Výstup ---
    $distance_km   = round($totalDistM / 1000, 3);
    $speed_max_kmh = round($speedMaxMS * 3.6, 3);

    $avgSpeedMS_moving = ($movingTimeS > 0) ? ($totalDistM / $movingTimeS) : 0.0;
    $avgSpeedMS_total  = ($durationS   > 0) ? ($totalDistM / $durationS)   : 0.0;

    $elevation_min = is_finite($eleMin) ? round($eleMin, 1) : null;
    $elevation_max = is_finite($eleMax) ? round($eleMax, 1) : null;

    return [
        'track_name'        => $trackName ?: null,
        'color'             => $color,
        'device'            => ($device !== '' && $device !== null) ? $device : null,
        'date_start'        => $timeStart ? date('Y-m-d H:i:s', $timeStart) : null,
        'date_end'          => $timeEnd   ? date('Y-m-d H:i:s', $timeEnd)   : null,
        'duration'          => $durationS ?: null,
        'moving_time'       => $movingTimeS ?: null,
        'stopped_time'      => $stoppedTimeS ?: null,
        'distance_km'       => $distance_km,
        'ascent'            => round($ascentM, 1),
        'descent'           => round($descentM, 1),
        'elevation_min'     => $elevation_min,
        'elevation_max'     => $elevation_max,
        'speed_max'         => $speed_max_kmh,
        'speed_avg'         => round($avgSpeedMS_moving * 3.6, 3),
        'speed_avg_total'   => round($avgSpeedMS_total  * 3.6, 3),
        'avg_ascent_rate'   => $avgAscentRate,
        'avg_descent_rate'  => $avgDescentRate,
        'max_ascent_rate'   => $maxAscentRate,
        'max_descent_rate'  => $maxDescentRate,
        'bounds'            => $bounds,
        'trackpoints_count' => $trackpointsCount,
    ];
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