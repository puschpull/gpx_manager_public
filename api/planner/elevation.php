<?php
declare(strict_types=1);

/**
 * api/planner/elevation.php — nadmořské výšky pro výškový profil v Plánovači.
 *
 * GET ?points=lat,lon;lat,lon;...
 *
 * Primárně Mapy.com Elevation API, při jakémkoli problému spadne zpátky na
 * Open-Meteo. Změřeno na čtyřech reálných trasách autora (40 bodů z každé,
 * porovnáno proti barometru v GPX): rozptyl odchylky u Mapy.com 5,7 / 8,1 /
 * 5,0 / 23,6 m proti 9,7 / 7,6 / 10,7 / 31,7 m u Open-Meteo; medián odchylky
 * byl u Mapy.com lepší ve všech čtyřech případech. Důvod je prostý — pro
 * Česko mají podrobný výškový model, kdežto Open-Meteo jede na globálním
 * s krokem 90 m. V zahraničí ta výhoda mizí, proto je záloha plnohodnotná,
 * ne nouzová.
 *
 * Mapy.com zvládne 256 bodů na požadavek (do ledna 2026 navíc platilo, že
 * všechny musely ležet v oblasti 1°×1° — to omezení padlo). Open-Meteo bere
 * 100, proto se pro zálohu vstup dělí.
 *
 * csrf: no | admin: ne — stejný režim jako route.php: návštěvník smí jen
 * s povoleným plánovačem a s per-IP rate limitem (utrácí API kvótu).
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';

ajax_endpoint(function (): array {
    if (empty($_SESSION['is_admin'])) {
        $visible = (array)get_app_config('visible_pages', all_pages());
        if (!in_array('planner', $visible, true)) {
            http_response_code(403);
            return ['ok' => false, 'error' => 'Forbidden'];
        }
        $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rateFile = sys_get_temp_dir() . '/gpx_planner_elev_' . md5($ip) . '.txt';
        $now      = time();
        $hits = is_file($rateFile)
            ? array_filter(
                explode("\n", (string)file_get_contents($rateFile)),
                static fn($t) => (int)$t > $now - 60
              )
            : [];
        if (count($hits) >= 20) {
            http_response_code(429);
            return ['ok' => false, 'error' => 'Příliš mnoho požadavků — chvíli počkejte.'];
        }
        $hits[] = (string)$now;
        file_put_contents($rateFile, implode("\n", array_values($hits)), LOCK_EX);
    }

    // ---- Vstup ----
    $pts = [];
    foreach (explode(';', (string)($_GET['points'] ?? '')) as $pair) {
        $parts = explode(',', trim($pair));
        if (count($parts) !== 2) continue;
        $lat = (float)$parts[0];
        $lon = (float)$parts[1];
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) continue;
        $pts[] = ['lat' => $lat, 'lon' => $lon];
    }
    if (count($pts) < 2) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Zadejte alespoň 2 body.'];
    }
    if (count($pts) > 256) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Příliš mnoho bodů (max 256).'];
    }

    $elev   = planner_elev_mapycom($pts);
    $source = 'mapycom';

    if ($elev === null) {
        $elev   = planner_elev_openmeteo($pts);
        $source = 'openmeteo';
    }
    if ($elev === null) {
        return ['ok' => false, 'error' => 'Výšky se nepodařilo načíst.'];
    }

    return ['ok' => true, 'source' => $source, 'elevation' => $elev];
}, ['csrf' => false, 'admin' => false, 'name' => 'planner/elevation']);

/**
 * Stáhne obsah URL (curl, jinak file_get_contents). Vrací null při chybě.
 */
function planner_http_get(string $url, int $timeout = 15): ?string
{
    if (function_exists('curl_init')) {
        $ch   = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ];
        if (defined('CURL_CA_BUNDLE') && CURL_CA_BUNDLE !== '' && is_file(CURL_CA_BUNDLE)) {
            $opts[CURLOPT_CAINFO] = CURL_CA_BUNDLE;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body === false || $http >= 400) ? null : (string)$body;
    }
    $ctx  = stream_context_create(['http' => ['timeout' => $timeout, 'header' => "Accept: application/json\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : (string)$body;
}

/**
 * Mapy.com Elevation API. Vrací pole výšek ve stejném pořadí jako vstup,
 * nebo null (pak volající sáhne po záloze).
 *
 * @param array<int, array{lat:float, lon:float}> $pts
 * @return array<int, float>|null
 */
function planner_elev_mapycom(array $pts): ?array
{
    if (!defined('MAPYCOM_API_KEY') || MAPYCOM_API_KEY === '') {
        return null;
    }
    // Pozor na pořadí: Mapy.com chtějí longitude,latitude
    $positions = implode(';', array_map(
        static fn(array $p): string => round($p['lon'], 6) . ',' . round($p['lat'], 6),
        $pts
    ));
    $url = 'https://api.mapy.com/v1/elevation?' . http_build_query([
        'apikey'    => MAPYCOM_API_KEY,
        'positions' => $positions,
    ]);

    $body = planner_http_get($url);
    if ($body === null) return null;

    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
        return null;
    }
    if (count($data['items']) !== count($pts)) {
        return null;   // neúplná odpověď — radši záloha než děravý profil
    }

    $out = [];
    foreach ($data['items'] as $it) {
        $v = $it['elevation'] ?? null;
        // -100000 = pro daný bod výšku neznají (dokumentovaná hodnota)
        if (!is_numeric($v) || (float)$v <= -9999.0) {
            return null;
        }
        $out[] = round((float)$v, 1);
    }
    return $out;
}

/**
 * Open-Meteo elevation — záloha. Bere max 100 bodů na požadavek, proto dělení.
 *
 * @param array<int, array{lat:float, lon:float}> $pts
 * @return array<int, float>|null
 */
function planner_elev_openmeteo(array $pts): ?array
{
    $out = [];
    foreach (array_chunk($pts, 100) as $chunk) {
        $lat = implode(',', array_map(static fn($p) => round($p['lat'], 5), $chunk));
        $lon = implode(',', array_map(static fn($p) => round($p['lon'], 5), $chunk));
        $body = planner_http_get('https://api.open-meteo.com/v1/elevation?latitude=' . $lat . '&longitude=' . $lon);
        if ($body === null) return null;

        $data = json_decode($body, true);
        $vals = $data['elevation'] ?? null;
        if (!is_array($vals) || count($vals) !== count($chunk)) return null;

        foreach ($vals as $v) {
            if (!is_numeric($v)) return null;
            $out[] = round((float)$v, 1);
        }
    }
    return $out;
}
