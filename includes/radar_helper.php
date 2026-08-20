<?php
declare(strict_types=1);

/**
 * ===========================================================
 *  radar_helper.php — radarové snímky ČHMÚ k trasám
 *
 *  Snímky se ukládají jako soubory do uploads/radar/<track_id>/,
 *  v databázi o nich není žádný záznam. Tenhle modul je proto
 *  jediné místo, které o jejich existenci ví: kdo je má, kolik
 *  jich má a u koho se ještě dají stáhnout.
 *
 *  Archiv ČHMÚ drží zhruba 7 dní zpět — u starších tras už radar
 *  získat nelze a nemá smysl to nabízet.
 * ===========================================================
 */

const CHMI_RADAR_BASE  = 'https://opendata.chmi.cz/meteorology/weather/radar/composite/maxz/png_masked/';
const RADAR_MARGIN_S   = 600;   // 10 min před/po trase (kontext)
const RADAR_MAX_FRAMES = 300;   // pojistka (~25 h výletu)
const RADAR_ARCHIVE_D  = 7;     // dokumentovaná hloubka archivu ČHMÚ ve dnech

/**
 * Kolik snímků má která trasa: [track_id => počet].
 * Jedno přečtení adresáře na request — výpis tras se na to ptá u každého řádku.
 */
function radar_counts(): array {
    static $counts = null;
    if ($counts !== null) return $counts;

    $counts = [];
    $base = uploads_fs('radar/');
    if (!is_dir($base)) return $counts;

    foreach (scandir($base) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (!ctype_digit($entry)) continue;
        $n = count(glob($base . $entry . DIRECTORY_SEPARATOR . '*.png') ?: []);
        if ($n > 0) $counts[(int)$entry] = $n;
    }
    return $counts;
}

/** Počet stažených snímků jedné trasy (0 = žádné). */
function radar_frame_count(int $trackId): int {
    $c = radar_counts();
    return $c[$trackId] ?? 0;
}

/** Dá se pro trasu ještě radar stáhnout? (archiv ČHMÚ ~7 dní zpět) */
function radar_window_open(?string $dateStart): bool {
    if (empty($dateStart)) return false;
    $ts = strtotime($dateStart);
    if ($ts === false) return false;
    return $ts >= time() - RADAR_ARCHIVE_D * 86400;
}

/**
 * Stáhne radarové snímky pro dobu trvání trasy.
 * Volá se z api/radar/fetch.php (tlačítko v přehrávači) i z importu.
 *
 * @return array{ok: bool, error?: string, downloaded?: int, cached?: int,
 *               missing?: int, total?: int, available?: int}
 */
function radar_fetch_for_track(\PDO $pdo, int $trackId): array {
    $st = $pdo->prepare('SELECT id, date_start, date_end FROM tracks WHERE id = ?');
    $st->execute([$trackId]);
    $tr = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$tr || empty($tr['date_start'])) {
        return ['ok' => false, 'error' => 'Trasa nenalezena nebo nemá časové údaje.'];
    }

    // Lokální čas trasy → UTC, zarovnat na 5minutový krok
    $from = strtotime((string)$tr['date_start']) - RADAR_MARGIN_S;
    $to   = strtotime((string)($tr['date_end'] ?: $tr['date_start'])) + RADAR_MARGIN_S;
    if ($to < $from) { $to = $from; }
    $from = (int)(floor($from / 300) * 300);
    $to   = (int)(ceil($to / 300) * 300);

    if ((int)(($to - $from) / 300) + 1 > RADAR_MAX_FRAMES) {
        $to = $from + (RADAR_MAX_FRAMES - 1) * 300;
    }

    $dir = uploads_fs('radar/' . $trackId . '/');
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Nelze vytvořit adresář pro radar.'];
    }

    // Stahování desítek snímků po 5 minutách trvá déle než výchozí limit
    @set_time_limit(180);

    $downloaded = 0; $cached = 0; $missing = 0; $total = 0;

    for ($t = $from; $t <= $to; $t += 300) {
        $total++;
        $stamp  = gmdate('YmdHi', $t);                       // UTC
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
}
