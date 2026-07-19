<?php
declare(strict_types=1);

/**
 * api/planner/route.php — routing pro Plánovač výšlapu (Mapy.com)
 * GET ?points=lat,lon;lat,lon;...&profile=foot_hiking
 *
 * Spočítá trasu po cestách přes zadané body. Mapy.com má limit start + end
 * + 15 mezibodů na požadavek → delší seznamy se řetězí po úsecích a
 * geometrie se spojí. Volání běží server-side (API klíč zůstává na serveru).
 *
 * csrf: no | admin: yes — každé volání utrácí Mapy.com API kvótu,
 * plánovač je jen pro admina (stejně jako VT routing).
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';

ajax_endpoint(function (): array {
    if (!defined('MAPYCOM_API_KEY') || MAPYCOM_API_KEY === '') {
        return ['ok' => false, 'error' => 'Chybí Mapy.com API klíč.'];
    }

    // ---- Vstup: body ----
    $raw = (string)($_GET['points'] ?? '');
    $pts = [];
    foreach (explode(';', $raw) as $pair) {
        $parts = explode(',', trim($pair));
        if (count($parts) !== 2) continue;
        $lat = (float)$parts[0];
        $lon = (float)$parts[1];
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 || ($lat == 0.0 && $lon == 0.0)) continue;
        $pts[] = ['lat' => $lat, 'lon' => $lon];
    }
    if (count($pts) < 2) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Zadejte alespoň 2 body.'];
    }
    if (count($pts) > 30) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Maximálně 30 bodů.'];
    }

    $profile = (string)($_GET['profile'] ?? 'foot_hiking');
    $allowedProfiles = ['foot_fast', 'foot_hiking', 'bike_road', 'bike_mountain', 'car_fast', 'car_short'];
    if (!in_array($profile, $allowedProfiles, true)) {
        $profile = 'foot_hiking';
    }

    // ---- Řetězení: max 17 bodů na jeden Mapy.com požadavek (start + 15 + end).
    //      Další úsek začíná posledním bodem předchozího → geometrie navazuje. ----
    $chunks = [];
    $maxPerReq = 17;
    for ($i = 0; $i < count($pts) - 1; $i += $maxPerReq - 1) {
        $chunk = array_slice($pts, $i, $maxPerReq);
        if (count($chunk) >= 2) $chunks[] = $chunk;
    }

    $fmt = static fn(array $p): string => round($p['lon'], 6) . ',' . round($p['lat'], 6); // lon,lat

    $coords    = [];   // [lat, lon] pro Leaflet
    $lengthM   = 0.0;
    $durationS = 0.0;

    foreach ($chunks as $chunk) {
        $params = [
            'apikey'    => MAPYCOM_API_KEY,
            'start'     => $fmt($chunk[0]),
            'end'       => $fmt($chunk[count($chunk) - 1]),
            'routeType' => $profile,
            'format'    => 'geojson',
        ];
        $mids = array_slice($chunk, 1, -1);
        if ($mids) {
            $params['waypoints'] = implode(';', array_map($fmt, $mids));
        }
        $url = 'https://api.mapy.com/v1/routing/route?' . http_build_query($params);

        $body = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ];
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
            $ctx  = stream_context_create(['http' => ['timeout' => 20, 'header' => "Accept: application/json\r\n"]]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) {
                return ['ok' => false, 'error' => 'Mapy.com routing nelze kontaktovat.'];
            }
        }

        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'Neplatná odpověď z Mapy.com.'];
        }

        $lineLonLat = planner_find_linestring($data);
        if (!$lineLonLat) {
            return ['ok' => false, 'error' => 'Odpověď neobsahuje geometrii trasy (bod mimo cestní síť?).'];
        }

        foreach ($lineLonLat as $c) {
            if (is_array($c) && count($c) >= 2) {
                $pt = [(float)$c[1], (float)$c[0]];
                // nespojovat duplicitní bod na švu dvou úseků
                $last = end($coords);
                if ($last === false || $last[0] !== $pt[0] || $last[1] !== $pt[1]) {
                    $coords[] = $pt;
                }
            }
        }

        $len = $data['length']   ?? ($data['routes'][0]['length']   ?? null);
        $dur = $data['duration'] ?? ($data['routes'][0]['duration'] ?? null);
        if ($len !== null) $lengthM   += (float)$len;
        if ($dur !== null) $durationS += (float)$dur;
    }

    if (count($coords) < 2) {
        return ['ok' => false, 'error' => 'Trasu se nepodařilo sestavit.'];
    }

    return [
        'ok'         => true,
        'profile'    => $profile,
        'coords'     => $coords,
        'length_m'   => round($lengthM),
        'duration_s' => round($durationS),
        'segments'   => count($chunks),
    ];
}, ['csrf' => false, 'admin' => true, 'name' => 'planner/route']);

/**
 * Rekurzivně najde první LineString (pole [lon,lat] dvojic) v dekódovaném JSON.
 * (Stejný vzor jako vt_find_linestring v api/virtual_tracks/route.php.)
 * @return array|null
 */
function planner_find_linestring($node): ?array
{
    if (!is_array($node)) return null;

    if (isset($node['type'], $node['coordinates'])
        && $node['type'] === 'LineString' && is_array($node['coordinates'])) {
        return $node['coordinates'];
    }
    if (isset($node[0]) && is_array($node[0]) && count($node[0]) >= 2
        && is_numeric($node[0][0] ?? null) && is_numeric($node[0][1] ?? null)
        && count($node) >= 2) {
        return $node;
    }
    foreach ($node as $v) {
        $found = planner_find_linestring($v);
        if ($found) return $found;
    }
    return null;
}
