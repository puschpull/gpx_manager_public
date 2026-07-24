<?php
declare(strict_types=1);

/**
 * tools/cleanup_uploads.php
 *
 * Úklid ODVOZENÝCH a OSIŘELÝCH dat ve složce uploads/. Sesterský nástroj
 * k cleanup_orphan_thumbs.php, který řeší jen náhledy.
 *
 * Co uklízí:
 *   1) uploads/_tile_cache/  — OSM dlaždice starší než TTL (30 dní, viz
 *      includes/generate_thumb.php). TTL je v aplikaci „líné": expirovaná
 *      dlaždice se přepíše až při dalším požadavku, jinak leží dál napořád.
 *   2) uploads/_poi_cache/   — POI odpovědi starší než TTL (7 dní, viz
 *      api/poi/bbox.php). Stejný problém.
 *   3) uploads/radar/<id>/   — radarové snímky ČHMÚ pro trasy, které už
 *      v databázi nejsou (zbytky po trasách smazaných před 7/2026, kdy
 *      delete.php radar ještě neuklízel).
 *   4) uploads/*.gpx.bak     — zálohy z in-place filtrace (jen s --bak).
 *
 * BEZPEČNOST:
 *  - Výchozí režim je DRY-RUN — jen vypíše, co by udělal, nic nezmění.
 *  - Body 1–3 jsou odvozená data: dlaždice i POI se stáhnou znovu samy,
 *    radar u smazané trasy už nikdo nezobrazí. Proto se mažou rovnou.
 *  - Bod 4 jsou UŽIVATELSKÁ data (předfiltrační verze GPX), proto je
 *    vypnutý a i s --apply se jen PŘESUNE do uploads/_bak_quarantine/.
 *  - Nikdy nesáhne na *.gpx (mimo .bak), na fotky ani na databázi.
 *
 * SPUŠTĚNÍ (jen z příkazové řádky):
 *   php tools/cleanup_uploads.php                  → DRY-RUN, nic nemění
 *   php tools/cleanup_uploads.php --apply          → uklidí body 1–3
 *   php tools/cleanup_uploads.php --apply --bak    → navíc odloží .bak do karantény
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Tento skript lze spustit jen z příkazové řádky.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);

$APPLY = in_array('--apply', $argv, true);
$BAK   = in_array('--bak',   $argv, true);

const TILE_TTL_S = 30 * 24 * 3600;   // shodné s includes/generate_thumb.php
const POI_TTL_S  =  7 * 24 * 3600;   // shodné s api/poi/bbox.php

$uploads = rtrim(uploads_fs(''), '/\\');
if (!is_dir($uploads)) {
    exit("Složka uploads/ nenalezena: $uploads\n");
}

echo "=== Úklid uploads/ ===\n";
echo "Složka: $uploads\n";
echo "Režim:  " . ($APPLY ? 'APPLY (mění soubory)' : 'DRY-RUN (nic nemění)') . "\n\n";

$freed = 0;

/** Smaže soubory starší než $ttl v adresáři; vrací [počet, bajtů]. */
function sweep_expired(string $dir, int $ttl, bool $apply): array {
    if (!is_dir($dir)) return [0, 0];
    $n = 0; $bytes = 0; $now = time();
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        if ($now - $f->getMTime() < $ttl) continue;
        $n++; $bytes += $f->getSize();
        if ($apply) @unlink($f->getPathname());
    }
    return [$n, $bytes];
}

/* ---------- 1) OSM tile cache ---------- */
[$n, $b] = sweep_expired($uploads . '/_tile_cache', TILE_TTL_S, $APPLY);
printf("1) Dlaždice starší %d dní:  %4d souborů, %s\n", TILE_TTL_S / 86400, $n, fmt_bytes($b));
$freed += $b;

/* ---------- 2) POI cache ---------- */
[$n, $b] = sweep_expired($uploads . '/_poi_cache', POI_TTL_S, $APPLY);
printf("2) POI cache starší %d dní: %4d souborů, %s\n", POI_TTL_S / 86400, $n, fmt_bytes($b));
$freed += $b;

/* ---------- 3) Osiřelé radarové snímky ---------- */
$radarRoot = $uploads . '/radar';
$orphanN = 0; $orphanB = 0; $orphanIds = [];
if (is_dir($radarRoot)) {
    $liveIds = array_flip(array_map('intval',
        $pdo->query('SELECT id FROM tracks')->fetchAll(PDO::FETCH_COLUMN)));
    foreach (glob($radarRoot . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $id = (int)basename($dir);
        if ($id > 0 && isset($liveIds[$id])) continue;      // trasa žije → nechat
        $orphanIds[] = basename($dir);
        foreach (glob($dir . '/*.png') ?: [] as $png) {
            $orphanN++; $orphanB += (int)@filesize($png);
            if ($APPLY) @unlink($png);
        }
        if ($APPLY) @rmdir($dir);
    }
}
printf("3) Radar smazaných tras:    %4d snímků, %s%s\n", $orphanN, fmt_bytes($orphanB),
    $orphanIds ? '  (trasy ' . implode(', ', $orphanIds) . ')' : '');
$freed += $orphanB;

/* ---------- 4) Zálohy z in-place filtrace ---------- */
$baks = glob($uploads . '/*.gpx.bak') ?: [];
$bakB = array_sum(array_map(fn($f) => (int)@filesize($f), $baks));
if (!$BAK) {
    printf("4) Zálohy *.gpx.bak:        %4d souborů, %s  (přeskočeno — spusť s --bak)\n",
        count($baks), fmt_bytes($bakB));
} else {
    $quar = $uploads . '/_bak_quarantine';
    if ($APPLY && $baks && !is_dir($quar)) @mkdir($quar, 0755, true);
    $moved = 0;
    foreach ($baks as $f) {
        // Zálohu odložíme jen když originál pořád existuje — jinak je to
        // jediná kopie těch dat a nesmí se hnout.
        if (!is_file(substr($f, 0, -4))) {
            echo "   ! originál chybí, nechávám: " . basename($f) . "\n";
            continue;
        }
        $moved++;
        if ($APPLY) @rename($f, $quar . '/' . basename($f));
    }
    printf("4) Zálohy *.gpx.bak:        %4d souborů, %s → karanténa _bak_quarantine/\n",
        $moved, fmt_bytes($bakB));
}

echo "\n";
printf("Celkem k uvolnění: %s\n", fmt_bytes($freed));
echo $APPLY
    ? "Hotovo.\n"
    : "DRY-RUN — nic se nezměnilo. Pro provedení spusť znovu s --apply.\n";

function fmt_bytes(int $b): string {
    if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024)    return round($b / 1024) . ' kB';
    return $b . ' B';
}
