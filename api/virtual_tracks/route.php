<?php
declare(strict_types=1);

/**
 * api/virtual_tracks/route.php — EXPERIMENT: odhad trasy po cestách (Mapy.com routing)
 * GET ?id=<virtual_track_id>
 *
 * Vezme polohy fotek jako průjezdní body, požádá Mapy.com routing API o pěší
 * trasu po reálných cestách a vrátí geometrii pro vykreslení. Volání běží
 * server-side (API klíč zůstává na serveru). Výsledek je ODHAD.
 *
 * csrf: no | admin: no  (read-only; volá se jen na vyžádání)
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';

ajax_endpoint(function () use ($pdo): array {
    if (!defined('MAPYCOM_API_KEY') || MAPYCOM_API_KEY === '') {
        return ['ok' => false, 'error' => 'Chybí Mapy.com API klíč.'];
    }

    $vtId = (int)($_GET['id'] ?? 0);
    if (!$vtId) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Chybí id'];
    }

    $stmt = $pdo->prepare("
        SELECT lat, lon FROM track_photos
        WHERE virtual_track_id = ? AND lat IS NOT NULL AND lon IS NOT NULL
          AND (visible IS NULL OR visible = 1)
        ORDER BY taken_at ASC, id ASC
    ");
    $stmt->execute([$vtId]);
    $pts = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    if (count($pts) < 2) {
        return ['ok' => false, 'error' => 'Trasa má málo bodů.'];
    }

    // Volitelný úsek [from..to] (indexy do chronologického pořadí fotek) — mód po úsecích.
    // Stejné řazení i filtr jako points.php → indexy klienta odpovídají.
    $from = array_key_exists('from', $_GET) ? (int)$_GET['from'] : null;
    $to   = array_key_exists('to',   $_GET) ? (int)$_GET['to']   : null;
    if ($from !== null && $to !== null) {
        $n    = count($pts);
        $from = max(0, min($from, $n - 1));
        $to   = max(0, min($to,   $n - 1));
        if ($to - $from < 1) {
            return ['ok' => false, 'error' => 'Úsek má málo bodů.'];
        }
        $pts = array_slice($pts, $from, $to - $from + 1);
    }

    // Podvzorkování: start + end + max 15 mezibodů (limit Mapy.com).
    $maxWaypoints = 15;
    $start = $pts[0];
    $end   = $pts[count($pts) - 1];
    $mids  = array_slice($pts, 1, -1);
    if (count($mids) > $maxWaypoints) {
        $step = count($mids) / $maxWaypoints;
        $picked = [];
        for ($i = 0; $i < $maxWaypoints; $i++) {
            $picked[] = $mids[(int)floor($i * $step)];
        }
        $mids = $picked;
    }

    // Profil routingu (Mapy.com routeType). Pěšky / kolo / auto varianty.
    $profile = (string)($_GET['profile'] ?? 'foot_fast');
    $allowedProfiles = ['foot_fast', 'foot_hiking', 'bike_road', 'bike_mountain', 'car_fast', 'car_short'];
    if (!in_array($profile, $allowedProfiles, true)) {
        $profile = 'foot_fast';
    }

    $fmt = fn($p) => round((float)$p['lon'], 6) . ',' . round((float)$p['lat'], 6); // lon,lat
    $params = [
        'apikey'    => MAPYCOM_API_KEY,
        'start'     => $fmt($start),
        'end'       => $fmt($end),
        'routeType' => $profile,
        'format'    => 'geojson',
    ];
    if ($mids) {
        $params['waypoints'] = implode(';', array_map($fmt, $mids));
    }
    $url = 'https://api.mapy.com/v1/routing/route?' . http_build_query($params);

    // Server-side volání
    $body = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ];
        // Lokální Windows WAMP: curl nemá systémový CA store → volitelný bundle.
        if (defined('CURL_CA_BUNDLE') && CURL_CA_BUNDLE !== '' && is_file(CURL_CA_BUNDLE)) {
            $opts[CURLOPT_CAINFO] = CURL_CA_BUNDLE;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $http >= 400) {
            return ['ok' => false, 'error' => "Mapy.com routing selhal (HTTP $http)."];
        }
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'header' => "Accept: application/json\r\n"]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return ['ok' => false, 'error' => 'Mapy.com routing nelze kontaktovat.'];
        }
    }

    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'Neplatná odpověď z Mapy.com.'];
    }

    // Najít GeoJSON LineString souřadnice [lon,lat] kdekoli v odpovědi
    $coordsLonLat = vt_find_linestring($data);
    if (!$coordsLonLat) {
        return ['ok' => false, 'error' => 'Odpověď neobsahuje geometrii trasy.'];
    }

    // lon,lat → lat,lon pro Leaflet
    $coords = [];
    foreach ($coordsLonLat as $c) {
        if (is_array($c) && count($c) >= 2) {
            $coords[] = [(float)$c[1], (float)$c[0]];
        }
    }

    // délka/čas, pokud jsou v odpovědi
    $length = $data['length'] ?? ($data['routes'][0]['length'] ?? null);
    $duration = $data['duration'] ?? ($data['routes'][0]['duration'] ?? null);

    return [
        'ok'         => true,
        'profile'    => $profile,
        'coords'     => $coords,
        'length_m'   => $length !== null ? (float)$length : null,
        'duration_s' => $duration !== null ? (float)$duration : null,
        'waypoints'  => count($mids) + 2,
        'from'       => $from,
        'to'         => $to,
    ];
}, ['csrf' => false, 'admin' => false, 'name' => 'virtual_tracks/route']);

/**
 * Rekurzivně najde první LineString (pole [lon,lat] dvojic) v dekódovaném JSON.
 * @return array|null
 */
function vt_find_linestring($node): ?array
{
    if (!is_array($node)) return null;

    // GeoJSON geometry?
    if (isset($node['type'], $node['coordinates'])
        && $node['type'] === 'LineString' && is_array($node['coordinates'])) {
        return $node['coordinates'];
    }
    // přímo pole souřadnic [[lon,lat],...]?
    if (isset($node[0]) && is_array($node[0]) && count($node[0]) >= 2
        && is_numeric($node[0][0] ?? null) && is_numeric($node[0][1] ?? null)
        && count($node) >= 2) {
        return $node;
    }
    foreach ($node as $v) {
        $found = vt_find_linestring($v);
        if ($found) return $found;
    }
    return null;
}
