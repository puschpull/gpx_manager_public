<?php
declare(strict_types=1);

/**
 * api/radar/fetch.php — stáhne skutečné radarové snímky ČHMÚ pro dobu trvání trasy
 * POST track_id | csrf: yes | admin: yes
 *
 * Zdroj: https://opendata.chmi.cz/meteorology/weather/radar/composite/maxz/png_masked/
 * Soubory: pacz2gmaps3.z_max3d.YYYYMMDD.HHMM.0.png — čas je UTC, krok 5 minut,
 * archiv ČHMÚ drží jen ~7 dní zpět (starší výlety už radar nezískají).
 *
 * Snímky se ukládají do uploads/radar/<track_id>/<YYYYMMDDHHMM>.png
 */

require_once __DIR__ . '/../../includes/public_access.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/ajax.php';

const CHMI_RADAR_BASE = 'https://opendata.chmi.cz/meteorology/weather/radar/composite/maxz/png_masked/';
const RADAR_MARGIN_S  = 600;   // 10 min před/po trase (kontext)
const RADAR_MAX_FRAMES = 300;  // pojistka (~25 h výletu)

ajax_endpoint(function () use ($pdo): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return ['ok' => false, 'error' => 'Method not allowed'];
    }

    $trackId = (int)($_POST['track_id'] ?? 0);
    if ($trackId <= 0) {
        http_response_code(400);
        return ['ok' => false, 'error' => 'Chybí track_id.'];
    }

    $st = $pdo->prepare('SELECT id, date_start, date_end FROM tracks WHERE id = ?');
    $st->execute([$trackId]);
    $tr = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$tr || empty($tr['date_start'])) {
        http_response_code(404);
        return ['ok' => false, 'error' => 'Trasa nenalezena nebo nemá časové údaje.'];
    }

    // Lokální čas trasy → UTC, zarovnat na 5minutový krok
    $from = strtotime((string)$tr['date_start']) - RADAR_MARGIN_S;
    $to   = strtotime((string)($tr['date_end'] ?: $tr['date_start'])) + RADAR_MARGIN_S;
    if ($to < $from) { $to = $from; }
    $from = (int)(floor($from / 300) * 300);
    $to   = (int)(ceil($to / 300) * 300);

    $frames = (int)(($to - $from) / 300) + 1;
    if ($frames > RADAR_MAX_FRAMES) {
        $to = $from + (RADAR_MAX_FRAMES - 1) * 300;
    }

    $dir = uploads_fs('radar/' . $trackId . '/');
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Nelze vytvořit adresář pro radar.'];
    }

    // Stahování desítek snímků po 5 minutách trvá déle než default limit
    @set_time_limit(180);

    $downloaded = 0; $cached = 0; $missing = 0; $total = 0;

    for ($t = $from; $t <= $to; $t += 300) {
        $total++;
        $stamp = gmdate('YmdHi', $t);                       // UTC
        $target = $dir . $stamp . '.png';
        if (is_file($target) && filesize($target) > 0) { $cached++; continue; }

        $url = CHMI_RADAR_BASE . 'pacz2gmaps3.z_max3d.'
             . gmdate('Ymd', $t) . '.' . gmdate('Hi', $t) . '.0.png';

        $body = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => ['User-Agent: GPX-Manager-Radar'],
            ];
            if (defined('CURL_CA_BUNDLE') && CURL_CA_BUNDLE !== '' && is_file(CURL_CA_BUNDLE)) {
                $opts[CURLOPT_CAINFO] = CURL_CA_BUNDLE;
            }
            curl_setopt_array($ch, $opts);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $code !== 200) { $body = null; }
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 15, 'header' => "User-Agent: GPX-Manager-Radar\r\n"]]);
            $raw = @file_get_contents($url, false, $ctx);
            $body = ($raw !== false && $raw !== '') ? $raw : null;
        }

        // Mimo archiv (starší ~7 dní) nebo chybějící snímek → přeskočit
        if ($body === null || strlen($body) < 100 || substr($body, 1, 3) !== 'PNG') {
            $missing++;
            continue;
        }

        if (@file_put_contents($target, $body, LOCK_EX) !== false) {
            $downloaded++;
        } else {
            $missing++;
        }
    }

    return [
        'ok'         => true,
        'downloaded' => $downloaded,
        'cached'     => $cached,
        'missing'    => $missing,
        'total'      => $total,
        'available'  => $downloaded + $cached,
    ];
}, ['csrf' => true, 'admin' => true, 'name' => 'radar/fetch']);
