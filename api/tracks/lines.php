<?php
declare(strict_types=1);

/**
 * api/tracks/lines.php — PUBLIC read-only: zjednodušené čáry tras ve výřezu
 * GET ?minlat=&maxlat=&minlon=&maxlon=
 * csrf: no | admin: no — trasy jsou veřejné stejně jako jejich detail.
 *
 * Pro vrstvu „Moje trasy“ na stránce Mapa. Trasa se vybere, když se její
 * obdélník (tracks.bounds) překrývá s výřezem. Čára je zjednodušená
 * Douglasem–Peuckerem na 10 m a uložená v uploads/_line_cache/<id>.json;
 * cache se obnoví, když je GPX novější.
 *
 * Velký výřez (víc než ~3° × 5°) se odmítne — klient pak vyzve k přiblížení.
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';
require_once __DIR__ . '/../../includes/geojson_helper.php';
require_once __DIR__ . '/../../includes/track_title.php';

require_once __DIR__ . '/../../includes/track_lines.php';   // track_lines_cached()

const TRACK_LINES_MAX = 150;

ajax_endpoint(function () use ($pdo): array {
    $num = static function (string $k, float $lim): ?float {
        $v = $_GET[$k] ?? null;
        if (!is_string($v) || !is_numeric($v)) {
            return null;
        }
        $f = (float)$v;
        return abs($f) <= $lim ? $f : null;
    };
    $minLat = $num('minlat', 90);
    $maxLat = $num('maxlat', 90);
    $minLon = $num('minlon', 180);
    $maxLon = $num('maxlon', 180);
    if ($minLat === null || $maxLat === null || $minLon === null || $maxLon === null
        || $minLat >= $maxLat || $minLon >= $maxLon) {
        http_response_code(400);
        return ['ok' => false, 'error' => t('api_bad_param', 'Invalid parameter')];
    }
    if ($maxLat - $minLat > 3.0 || $maxLon - $minLon > 5.0) {
        return ['ok' => true, 'tooBig' => true, 'tracks' => []];
    }

    $rows = $pdo->query("
        SELECT id, filename, track_name, alt_title, place_name, date_start, distance_km, activity_type, color, bounds
        FROM   tracks
        WHERE  bounds IS NOT NULL AND bounds != ''
        ORDER  BY date_start DESC
    ")->fetchAll(\PDO::FETCH_ASSOC);

    $tracks    = [];
    $truncated = false;
    foreach ($rows as $r) {
        $b = json_decode((string)$r['bounds'], true);
        if (!is_array($b) || !isset($b['minlat'], $b['maxlat'], $b['minlon'], $b['maxlon'])) {
            continue;
        }
        if ($b['maxlat'] < $minLat || $b['minlat'] > $maxLat || $b['maxlon'] < $minLon || $b['minlon'] > $maxLon) {
            continue;
        }
        if (count($tracks) >= TRACK_LINES_MAX) {
            $truncated = true;
            break;
        }
        $segments = track_lines_cached((int)$r['id'], (string)$r['filename']);
        if (!$segments) {
            continue;
        }
        // Titulek jako na detailu; místo jen z databáze (prázdný řetězec =
        // nehledat), ať jeden dotaz nespustí desítky volání Mapy.com.
        $r['place_name'] = $r['place_name'] ?? '';
        $tracks[] = [
            'id'       => (int)$r['id'],
            'name'     => track_display_title($pdo, $r),
            'date'     => $r['date_start'] !== null ? substr((string)$r['date_start'], 0, 10) : null,
            'km'       => round((float)$r['distance_km'], 1),
            'activity' => activity_type_label((string)($r['activity_type'] ?? '')),
            'color'    => preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)$r['color']) ? (string)$r['color'] : null,
            'lines'    => $segments,
        ];
    }

    return ['ok' => true, 'tracks' => $tracks, 'truncated' => $truncated];
}, ['csrf' => false, 'admin' => false, 'name' => 'tracks/lines']);
