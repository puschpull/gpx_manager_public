<?php
declare(strict_types=1);

/**
 * api/csp_report.php — příjem hlášení o porušení Content-Security-Policy
 * csrf: no (posílá je prohlížeč sám, bez tokenu) | admin: no (hlásí i návštěvníci)
 *
 * Cíl hlavičky report-uri z includes/security.php. Zapisuje jeden řádek JSON
 * do logs/csp.log — nic nevrací a nic jiného nedělá. Protože je endpoint
 * veřejný, je omezený: jen POST, max 16 KB těla, max 5 MB logu, texty
 * zkrácené, z adres odstraněné parametry (mohou nést identifikátory).
 *
 * Čtení logu: podle blocked-uri + source-file + script-sample se pozná,
 * který vložený skript nebo obsluha by bez 'unsafe-inline' neběžela.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ajax.php';

const CSP_LOG_MAX_BYTES = 5 * 1024 * 1024;

ajax_endpoint(function (): array {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        return ['ok' => false];
    }

    $raw = (string)file_get_contents('php://input', false, null, 0, 16384);
    $doc = json_decode($raw, true);
    $r   = is_array($doc) ? ($doc['csp-report'] ?? $doc) : null;
    if (!is_array($r)) {
        http_response_code(400);
        return ['ok' => false];
    }

    // Jen cesta, bez parametrů a fragmentu; řídicí znaky pryč; délka omezena
    $clean = static function ($v, int $max = 200): string {
        $s = preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string)($v ?? '')) ?? '';
        return mb_substr($s, 0, $max);
    };
    $path = static function ($v) use ($clean): string {
        $s = (string)($v ?? '');
        $s = preg_replace('/[?#].*$/s', '', $s) ?? '';
        return $clean($s);
    };

    $entry = [
        't'         => date('Y-m-d H:i:s'),
        'page'      => $path($r['document-uri'] ?? ''),
        'directive' => $clean($r['effective-directive'] ?? $r['violated-directive'] ?? '', 60),
        'blocked'   => $path($r['blocked-uri'] ?? ''),
        'source'    => $path($r['source-file'] ?? ''),
        'line'      => (int)($r['line-number'] ?? 0),
        'sample'    => $clean($r['script-sample'] ?? '', 80),
        'mode'      => $clean($r['disposition'] ?? '', 10),
    ];

    $dir = __DIR__ . '/../logs';
    $log = $dir . '/csp.log';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    clearstatcache(true, $log);
    if (is_file($log) && filesize($log) > CSP_LOG_MAX_BYTES) {
        return ['ok' => true, 'stored' => false];   // log plný — ať ho nikdo nezahltí
    }
    @file_put_contents($log, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX);

    return ['ok' => true];
}, ['csrf' => false, 'admin' => false, 'name' => 'csp_report']);
