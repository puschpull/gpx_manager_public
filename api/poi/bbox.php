<?php
declare(strict_types=1);

/**
 * api/poi/bbox.php — turistické body zájmu z OpenStreetMap (Overpass API)
 * GET ?s=&w=&n=&e=  (bounding box: south, west, north, east)
 * csrf: no | admin: no — veřejná OSM data, read-only
 *
 * Kategorie: vrcholy, rozhledny/vyhlídky, přístřešky, prameny/studánky,
 * hrady a zříceniny. Server-side proxy: žádná změna CSP a hlavně diskový
 * cache (7 dní) na zaokrouhlené mřížce bboxů — Overpass má přísné rate
 * limity, opakované posuny mapy jdou z cache.
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';

ajax_endpoint(function (): array {
    $s = (float)($_GET['s'] ?? 0);
    $w = (float)($_GET['w'] ?? 0);
    $n = (float)($_GET['n'] ?? 0);
    $e = (float)($_GET['e'] ?? 0);

    if ($s >= $n || $w >= $e || $s < -90 || $n > 90 || $w < -180 || $e > 180) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Neplatný bbox.'];
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
        return ['ok' => false, 'error' => 'Výřez je příliš velký — přibliž mapu.'];
    }

    // ---- Diskový cache (TTL 7 dní) ----
    $cacheDir = uploads_fs('_poi_cache/');
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $cacheFile = $cacheDir . sprintf('poi_%.2f_%.2f_%.2f_%.2f.json', $s, $w, $n, $e);
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 7 * 86400) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return ['ok' => true, 'pois' => $cached, 'cached' => true];
        }
    }

    // ---- Overpass dotaz ----
    $bbox = sprintf('%.4f,%.4f,%.4f,%.4f', $s, $w, $n, $e);
    $query = "[out:json][timeout:15];("
        . "node[\"natural\"=\"peak\"]($bbox);"
        . "node[\"tourism\"=\"viewpoint\"]($bbox);"
        . "node[\"amenity\"=\"shelter\"]($bbox);"
        . "node[\"natural\"=\"spring\"]($bbox);"
        . "node[\"historic\"~\"^(castle|ruins)$\"]($bbox);"
        . "node[\"man_made\"=\"tower\"][\"tower:type\"=\"observation\"]($bbox);"
        . ");out body 400;";

    $body = false;
    if (function_exists('curl_init')) {
        $ch = curl_init('https://overpass-api.de/api/interpreter');
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'data=' . urlencode($query),
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: GPX-Manager-POI'],
        ];
        if (defined('CURL_CA_BUNDLE') && CURL_CA_BUNDLE !== '' && is_file(CURL_CA_BUNDLE)) {
            $opts[CURLOPT_CAINFO] = CURL_CA_BUNDLE;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $http >= 400) {
            return ['ok' => false, 'error' => "Overpass nedostupný (HTTP $http) — zkuste později."];
        }
    } else {
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'timeout' => 20,
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: GPX-Manager-POI\r\n",
            'content' => 'data=' . urlencode($query),
        ]]);
        $body = @file_get_contents('https://overpass-api.de/api/interpreter', false, $ctx);
        if ($body === false) {
            return ['ok' => false, 'error' => 'Overpass nedostupný — zkuste později.'];
        }
    }

    $data = json_decode((string)$body, true);
    if (!is_array($data) || !isset($data['elements'])) {
        return ['ok' => false, 'error' => 'Neplatná odpověď Overpassu.'];
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
