<?php
declare(strict_types=1);
/**
 * 0014_centroid_backfill.php — standalone backfill runner
 *
 * Populates centroid_lat / centroid_lon from the existing bounds JSON for
 * all tracks where centroid_lat IS NULL and bounds IS NOT NULL.
 *
 * Run ONCE from the command line after applying 0014_centroid.sql:
 *   php migrations/0014_centroid_backfill.php
 *
 * Idempotent: running twice is safe — the WHERE clause limits updates to
 * rows that still have NULL centroid_lat.
 *
 * bounds JSON format (from gpx_parser.php):
 *   {"minlat": ..., "maxlat": ..., "minlon": ..., "maxlon": ...}
 *
 * Covers: DB-9, DB-10
 */

// Only runnable from CLI
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script must be run from the command line.' . PHP_EOL);
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/config.php';
require_once $projectRoot . '/includes/db.php';

// Select rows that need backfilling
$stmt = $pdo->query(
    "SELECT id, bounds FROM tracks WHERE centroid_lat IS NULL AND bounds IS NOT NULL"
);

$update = $pdo->prepare(
    "UPDATE tracks SET centroid_lat = ?, centroid_lon = ? WHERE id = ?"
);

$updated  = 0;
$skipped  = 0;
$total    = 0;

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $total++;
    $b = json_decode((string)$row['bounds'], true);
    if (!is_array($b)) {
        $skipped++;
        continue;
    }

    // Support the canonical format produced by gpx_parser.php
    $minLat = $b['minlat'] ?? null;
    $maxLat = $b['maxlat'] ?? null;
    $minLon = $b['minlon'] ?? null;
    $maxLon = $b['maxlon'] ?? null;

    // Also handle alternative key casings just in case
    if ($minLat === null) $minLat = $b['minLat'] ?? null;
    if ($maxLat === null) $maxLat = $b['maxLat'] ?? null;
    if ($minLon === null) $minLon = $b['minLon'] ?? null;
    if ($maxLon === null) $maxLon = $b['maxLon'] ?? null;

    if ($minLat === null || $maxLat === null || $minLon === null || $maxLon === null) {
        $skipped++;
        continue;
    }

    $cLat = ((float)$minLat + (float)$maxLat) / 2.0;
    $cLon = ((float)$minLon + (float)$maxLon) / 2.0;

    $update->execute([$cLat, $cLon, (int)$row['id']]);
    $updated++;
}

echo "Backfill complete." . PHP_EOL;
echo "  Total rows checked : $total" . PHP_EOL;
echo "  Updated            : $updated" . PHP_EOL;
echo "  Skipped (bad JSON) : $skipped" . PHP_EOL;
