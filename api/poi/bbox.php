<?php
declare(strict_types=1);

/**
 * api/poi/bbox.php — turistické body zájmu z OpenStreetMap (Overpass API)
 * GET ?s=&w=&n=&e=  (bounding box: south, west, north, east)
 * csrf: no | admin: no — veřejná OSM data, read-only
 *
 * Kategorie: vrcholy, rozhledny/vyhlídky, přístřešky, prameny/studánky,
 * hrady a zříceniny. Server-side proxy: žádná změna CSP a hlavně diskový
 * cache (30 dní) na zaokrouhlené mřížce bboxů — Overpass má přísné rate
 * limity, opakované posuny mapy jdou z cache. Když Overpass neodpoví ani
 * záložní instance, vrátí se starší cache ('stale'), pokud existuje.
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';

/**
 * POST na Overpass. Vrací [tělo|false, HTTP kód] (0 = timeout / bez spojení).
 *
 * @return array{0: string|false, 1: int}
 */
function poi_overpass_post(string $url, string $query, int $timeout): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'data=' . urlencode($query),
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: GPX-Manager-POI'],
        ];
        if (defined('CURL_CA_BUNDLE') && CURL_CA_BUNDLE !== '' && is_file(CURL_CA_BUNDLE)) {
            $opts[CURLOPT_CAINFO] = CURL_CA_BUNDLE;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [is_string($body) ? $body : false, $http];
    }
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'timeout'       => $timeout,
        'ignore_errors' => true,
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: GPX-Manager-POI\r\n",
        'content'       => 'data=' . urlencode($query),
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $http = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $http = (int)$m[1];
    }
    return [$body, $http];
}

ajax_endpoint(function (): array {
    // Read-only and possibly ~55 s waiting for Overpass: release the session
    // lock first, otherwise every other request of the same browser (page
    // reload, other map layers) waits until Overpass answers.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $s = (float)($_GET['s'] ?? 0);
    $w = (float)($_GET['w'] ?? 0);
    $n = (float)($_GET['n'] ?? 0);
    $e = (float)($_GET['e'] ?? 0);

    if ($s >= $n || $w >= $e || $s < -90 || $n > 90 || $w < -180 || $e > 180) {
        http_response_code(400);
        return ['ok' => false, 'error' => t('api_bad_bbox')];
    }

    // Přichytit na mřížku 0.05° (rozšířit ven) → posuny mapy trefují cache
    $grid = 0.05;
    $s = floor($s / $grid) * $grid;
    $w = floor($w / $grid) * $grid;
    $n = ceil($n / $grid) * $grid;
    $e = ceil($e / $grid) * $grid;

    // Ochrana Overpassu: příliš velký výřez odmítnout (klient volá až od zoomu 11)
    if (($n - $s) > 1.2 || ($e - $w) > 1.8) {
        http_response_code(400);
        return ['ok' => false, 'error' => t('api_bbox_too_big')];
    }

    // ---- Diskový cache (TTL 30 dní — vrcholy, rozhledny a studánky se skoro nemění) ----
    $cacheDir = uploads_fs('_poi_cache/');
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $cacheFile = $cacheDir . sprintf('poi_%.2f_%.2f_%.2f_%.2f.json', $s, $w, $n, $e);
    $stale = null;   // starší cache — vrátí se, když Overpass neodpoví
    if (is_file($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            if ((time() - filemtime($cacheFile)) < 30 * 86400) {
                return ['ok' => true, 'pois' => $cached, 'cached' => true];
            }
            $stale = $cached;
        }
    }

    // ---- Overpass dotaz ----
    $bbox = sprintf('%.4f,%.4f,%.4f,%.4f', $s, $w, $n, $e);
    $query = "[out:json][timeout:25];("
        . "node[\"natural\"=\"peak\"]($bbox);"
        . "node[\"tourism\"=\"viewpoint\"]($bbox);"
        . "node[\"amenity\"=\"shelter\"]($bbox);"
        . "node[\"natural\"=\"spring\"]($bbox);"
        . "node[\"historic\"~\"^(castle|ruins)$\"]($bbox);"
        . "node[\"man_made\"=\"tower\"][\"tower:type\"=\"observation\"]($bbox);"
        . ");out body 400;";

    // Hlavní server bývá přetížený (25. 11. 2026 odpovídal za 39 s, dřív
    // jsme čekali jen 20 s) — čekat déle a ve zbylém čase zkusit zálohu.
    // Celkem nejvýš ~55 s: proxy na produkci (nginx/Varnish) čeká asi 60 s.
    set_time_limit(90);
    $deadline = microtime(true) + 55;
    $servers = [
        ['https://overpass-api.de/api/interpreter',         45],
        ['https://overpass.private.coffee/api/interpreter', 20],
    ];
    $body = false;
    $http = 0;
    foreach ($servers as [$url, $timeout]) {
        $left = (int)floor($deadline - microtime(true));
        if ($left < 5) {
            break;
        }
        [$body, $http] = poi_overpass_post($url, $query, min($timeout, $left));
        if ($body !== false && $http < 400 && is_array($d = json_decode((string)$body, true)) && isset($d['elements'])) {
            break;
        }
        $body = false;
    }
    if ($body === false) {
        if ($stale !== null) {
            return ['ok' => true, 'pois' => $stale, 'cached' => true, 'stale' => true];
        }
        return ['ok' => false, 'error' => t('api_overpass_down') . " (HTTP $http)"];
    }

    $data = json_decode((string)$body, true);
    if (!is_array($data) || !isset($data['elements'])) {
        return ['ok' => false, 'error' => t('api_overpass_invalid')];
    }

    $pois = [];
    foreach ($data['elements'] as $el) {
        if (!isset($el['lat'], $el['lon'])) continue;
        $tags = $el['tags'] ?? [];

        $type = null;
        if (($tags['natural'] ?? '') === 'peak')          $type = 'peak';
        elseif (($tags['tourism'] ?? '') === 'viewpoint') $type = 'viewpoint';
        elseif (($tags['amenity'] ?? '') === 'shelter')   $type = 'shelter';
        elseif (($tags['natural'] ?? '') === 'spring')    $type = 'spring';
        elseif (($tags['historic'] ?? '') === 'castle')   $type = 'castle';
        elseif (($tags['historic'] ?? '') === 'ruins')    $type = 'ruins';
        elseif (($tags['man_made'] ?? '') === 'tower')    $type = 'tower';
        if ($type === null) continue;

        $pois[] = [
            'lat'  => round((float)$el['lat'], 6),
            'lon'  => round((float)$el['lon'], 6),
            'type' => $type,
            'name' => isset($tags['name']) ? mb_substr((string)$tags['name'], 0, 80) : null,
            'ele'  => isset($tags['ele']) && is_numeric($tags['ele']) ? (int)round((float)$tags['ele']) : null,
        ];
    }

    @file_put_contents($cacheFile, json_encode($pois, JSON_UNESCAPED_UNICODE), LOCK_EX);

    return ['ok' => true, 'pois' => $pois, 'cached' => false];
}, ['csrf' => false, 'admin' => false, 'name' => 'poi/bbox']);
