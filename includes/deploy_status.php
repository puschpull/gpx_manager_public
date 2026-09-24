<?php
declare(strict_types=1);

/**
 * Stav nasazení pro kartu „Nasazení" v Administraci.
 *
 * Všechno se čte přímo ze souborů — žádné spouštění gitu ani volání GitHubu:
 *   - .git/HEAD + refs            … na kterém commitu web běží
 *   - refs/remotes/origin/<větev> … co je na GitHubu (aktualizuje ho `git fetch`
 *                                   v nasazovacím cronu; FETCH_HEAD = čas posledního fetch)
 *   - deploy.log                  … co cron nasadil / kde selhal (mimo webroot)
 *   - logs/csp.log                … co prohlížeče zablokovaly (Content-Security-Policy)
 *
 * Když některý zdroj chybí nebo není čitelný (lokální vývoj, open_basedir,
 * instalace bez gitu), karta to jen ohlásí — nic nepadá.
 */

/** Umístění deploy.log: .env DEPLOY_LOG_FILE, jinak ../../logs/deploy.log vedle webu. */
function deploy_log_path(): string {
    $env = trim((string)($_ENV['DEPLOY_LOG_FILE'] ?? ''));
    if ($env !== '') return $env;
    // Produkce: /home/<uživatel>/htdocs/<doména>  →  /home/<uživatel>/logs/deploy.log
    return dirname(__DIR__, 3) . '/logs/deploy.log';
}

/** Soubor čitelný bez varování (open_basedir hází warning už na is_file). */
function deploy_readable(string $path): bool {
    return (bool)@is_file($path) && (bool)@is_readable($path);
}

/** Posledních $n neprázdných řádků souboru (čte jen konec — log může růst). */
function deploy_tail(string $path, int $n, int $maxBytes = 65536): array {
    if (!deploy_readable($path)) return [];
    $size = (int)@filesize($path);
    $fh = @fopen($path, 'rb');
    if (!$fh) return [];
    if ($size > $maxBytes) fseek($fh, -$maxBytes, SEEK_END);
    $data = (string)stream_get_contents($fh);
    fclose($fh);
    $lines = array_values(array_filter(array_map('rtrim', explode("\n", $data)), 'strlen'));
    if ($size > $maxBytes) array_shift($lines);   // první řádek může být useknutý
    return array_slice($lines, -$n);
}

/** Počet řádků souboru (pro malé logy; u velkých vrací null). */
function deploy_line_count(string $path, int $maxBytes = 5 * 1024 * 1024): ?int {
    if (!deploy_readable($path) || (int)@filesize($path) > $maxBytes) return null;
    return substr_count((string)@file_get_contents($path), "\n");
}

/** SHA reference (loose soubor nebo packed-refs), jinak null. */
function deploy_git_ref(string $gitDir, string $ref): ?string {
    $loose = $gitDir . '/' . $ref;
    if (deploy_readable($loose)) {
        $sha = trim((string)@file_get_contents($loose));
        if (preg_match('/^[0-9a-f]{40}$/', $sha)) return $sha;
    }
    $packed = $gitDir . '/packed-refs';
    if (deploy_readable($packed)) {
        foreach (file($packed, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{40}) (\S+)$/', $line, $m) && $m[2] === $ref) return $m[1];
        }
    }
    return null;
}

/**
 * Hlavička commitu z volného objektu (.git/objects/xx/…): první řádek zprávy
 * a čas. Zabalené objekty (packfile) číst neumíme → null; karta pak vezme
 * popis z deploy.log.
 */
function deploy_git_commit_info(string $gitDir, string $sha): ?array {
    $obj = $gitDir . '/objects/' . substr($sha, 0, 2) . '/' . substr($sha, 2);
    if (!deploy_readable($obj) || !function_exists('gzuncompress')) return null;
    $raw = @gzuncompress((string)@file_get_contents($obj));
    if (!is_string($raw) || !str_starts_with($raw, 'commit ')) return null;
    $body = substr($raw, strpos($raw, "\0") + 1);
    [$head, $msg] = array_pad(explode("\n\n", $body, 2), 2, '');
    $time = null;
    if (preg_match('/^committer .* (\d{9,11}) [+-]\d{4}$/m', $head, $m)) $time = (int)$m[1];
    return ['subject' => trim(strtok($msg, "\n") ?: ''), 'time' => $time];
}

/** Řádek deploy.log → [čas (místní), stav, sha, popis]. Čas v logu je UTC. */
function deploy_parse_log_line(string $line): array {
    if (preg_match('/^\[?(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]?\s+(.*)$/', $line, $m)) {
        $local = $m[1];
        try {
            $dt = new DateTimeImmutable($m[1], new DateTimeZone('UTC'));
            $local = $dt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('j. n. H:i');
        } catch (\Throwable $e) { /* nechat jak je */ }
        $rest = $m[2];
        $state = 'info';
        if (preg_match('/^(CHYBA|ERROR)\b/u', $rest))           $state = 'error';
        elseif (preg_match('/^(POZOR|ČEKÁ)\b/u', $rest))        $state = 'warn';
        elseif (preg_match('/^(NASAZENO|RUČNĚ NASAZENO)\b/u', $rest)) $state = 'ok';
        return ['time' => $local, 'state' => $state, 'text' => $rest];
    }
    return ['time' => '', 'state' => 'info', 'text' => $line];
}

/** Všechno pro kartu v jednom poli. */
function deploy_status(string $branch = 'main'): array {
    $root   = dirname(__DIR__);
    $gitDir = $root . '/.git';
    $s = ['git' => false];

    if (deploy_readable($gitDir . '/HEAD')) {
        $s['git'] = true;
        $head = trim((string)@file_get_contents($gitDir . '/HEAD'));
        $headRef = str_starts_with($head, 'ref: ') ? substr($head, 5) : null;
        $s['branch'] = $headRef ? basename($headRef) : '(detached)';
        $s['head']   = $headRef ? deploy_git_ref($gitDir, $headRef) : (preg_match('/^[0-9a-f]{40}$/', $head) ? $head : null);
        $s['remote'] = deploy_git_ref($gitDir, 'refs/remotes/origin/' . $branch);
        $s['info']   = $s['head'] ? deploy_git_commit_info($gitDir, $s['head']) : null;
        $fetch = $gitDir . '/FETCH_HEAD';
        $s['fetched_at'] = deploy_readable($fetch) ? (int)@filemtime($fetch) : null;
    }

    $s['deploy_log']      = deploy_log_path();
    $s['deploy_readable'] = deploy_readable($s['deploy_log']);
    // Git při chybě vypíše hlášku na víc řádků bez časového razítka a nasazovací
    // skript za ni teprve zapíše „CHYBA: …". Řádky bez razítka proto patří
    // k NÁSLEDUJÍCÍMU záznamu (ověřeno na produkčním deploy.log 23. 9.).
    $entries = [];
    $pending = [];
    foreach (deploy_tail($s['deploy_log'], 60) as $line) {
        $e = deploy_parse_log_line($line);
        if ($e['time'] === '') {
            if (trim($line) !== '') $pending[] = trim($line);
            continue;
        }
        if ($pending) {
            $e['text'] .= ' — ' . implode(' ', $pending);
            $pending = [];
        }
        $entries[] = $e;
    }
    $s['deploy_lines'] = array_slice($entries, -6);

    $csp = $root . '/logs/csp.log';
    $s['csp_count'] = deploy_line_count($csp);
    $s['csp_lines'] = [];
    foreach (deploy_tail($csp, 5) as $line) {
        $r = json_decode($line, true);
        if (!is_array($r)) continue;
        $page = (string)($r['page'] ?? '');
        $s['csp_lines'][] = [
            'time'      => (string)($r['t'] ?? ''),
            'page'      => (string)(parse_url($page, PHP_URL_PATH) ?: $page),
            'directive' => (string)($r['directive'] ?? ''),
            'sample'    => (string)($r['sample'] ?? $r['blocked'] ?? ''),
        ];
    }
    return $s;
}
