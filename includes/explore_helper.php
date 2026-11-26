<?php
declare(strict_types=1);

/**
 * explore_helper.php — "explored squares": which map tiles (zoom 14, the grid
 * StatsHunters/VeloViewer use) the tracks passed through.
 *
 * Per-track tiles come from the simplified line cache (track_lines.php);
 * segments longer than ~300 m are interpolated so no crossed tile is skipped.
 * All tracks are kept in one file uploads/_explore_cache/tracks.json, keyed by
 * track id with the GPX mtime — only new or changed tracks are recomputed.
 *
 * Rebuilding can parse hundreds of GPX files, so only an admin request does it;
 * visitors get the last stored result (see api/explore/tiles.php).
 */

require_once __DIR__ . '/track_lines.php';

const EXPLORE_ZOOM = 14;

/** Tile x/y of a point at EXPLORE_ZOOM. @return array{0: int, 1: int} */
function explore_tile(float $lat, float $lon): array
{
    $n   = 2 ** EXPLORE_ZOOM;
    $lat = max(-85.0511, min(85.0511, $lat));
    $x   = (int)floor(($lon + 180.0) / 360.0 * $n);
    $r   = deg2rad($lat);
    $y   = (int)floor((1.0 - log(tan($r) + 1.0 / cos($r)) / M_PI) / 2.0 * $n);
    return [max(0, min($n - 1, $x)), max(0, min($n - 1, $y))];
}

/**
 * Tiles crossed by a line, as "x,y" keys.
 *
 * @param array<int, array<int, array{0: float, 1: float}>> $segments [[lat, lon], …]
 * @return list<string>
 */
function explore_tiles_of_lines(array $segments): array
{
    $tiles = [];
    foreach ($segments as $seg) {
        $prev = null;
        foreach ($seg as $p) {
            $lat = (float)$p[0];
            $lon = (float)$p[1];
            if ($prev !== null) {
                // ~300 m steps (0.0027° lat); tiles are ≥ 1.5 km wide in Europe
                $steps = (int)ceil(max(abs($lat - $prev[0]), abs($lon - $prev[1])) / 0.0027);
                for ($i = 1; $i < $steps; $i++) {
                    $f = $i / $steps;
                    [$x, $y] = explore_tile($prev[0] + ($lat - $prev[0]) * $f, $prev[1] + ($lon - $prev[1]) * $f);
                    $tiles["$x,$y"] = true;
                }
            }
            [$x, $y] = explore_tile($lat, $lon);
            $tiles["$x,$y"] = true;
            $prev = [$lat, $lon];
        }
    }
    return array_keys($tiles);
}

function explore_cache_file(): string
{
    return uploads_fs('_explore_cache') . DIRECTORY_SEPARATOR . 'tracks.json';
}

/**
 * Stored per-track tiles: id => ['m' => gpx mtime, 'a' => activity, 'd' => 'Y-m-d', 't' => [keys]].
 *
 * @return array<string, array<string, mixed>>
 */
function explore_load(): array
{
    try {
        $f = explore_cache_file();
    } catch (\RuntimeException $e) {
        return [];
    }
    if (!is_file($f)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($f), true);
    return is_array($data) ? $data : [];
}

/**
 * Brings the store up to date with the tracks table (new, changed, deleted).
 * Returns the store and how many tracks were (re)computed.
 *
 * @return array{0: array<string, array<string, mixed>>, 1: int, 2: bool}  [store, computed, complete]
 */
function explore_refresh(\PDO $pdo, float $timeBudget = 100.0): array
{
    $start = microtime(true);
    $store = explore_load();
    $rows  = $pdo->query("
        SELECT id, filename, activity_type, date_start FROM tracks
        WHERE bounds IS NOT NULL AND bounds != ''
    ")->fetchAll(\PDO::FETCH_ASSOC);

    $seen = [];
    $computed = 0;
    $complete = true;
    foreach ($rows as $r) {
        $id = (string)(int)$r['id'];
        $seen[$id] = true;
        try {
            $gpx = uploads_fs((string)$r['filename']);
        } catch (\RuntimeException $e) {
            continue;
        }
        $mtime = is_file($gpx) ? (int)filemtime($gpx) : 0;
        $act   = (string)($r['activity_type'] ?? '');
        $date  = $r['date_start'] !== null ? substr((string)$r['date_start'], 0, 10) : null;
        if (isset($store[$id]) && (int)$store[$id]['m'] === $mtime) {
            // Activity or date may change in the editor without touching the GPX
            $store[$id]['a'] = $act;
            $store[$id]['d'] = $date;
            continue;
        }
        if (microtime(true) - $start > $timeBudget) {
            $complete = false;
            continue;
        }
        $lines = track_lines_cached((int)$r['id'], (string)$r['filename']);
        $store[$id] = ['m' => $mtime, 'a' => $act, 'd' => $date, 't' => $lines ? explore_tiles_of_lines($lines) : []];
        $computed++;
    }
    foreach (array_keys($store) as $id) {
        if (!isset($seen[(string)$id])) {
            unset($store[$id]);
            $computed++;
        }
    }

    if ($computed > 0) {
        $f   = explore_cache_file();
        $dir = dirname($f);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($f, json_encode($store), LOCK_EX);
    }
    return [$store, $computed, $complete];
}

/**
 * Aggregates the store: visited tiles with count and first year, the largest
 * cluster (tiles whose four neighbours are visited, 4-connected) and the
 * largest square of visited tiles.
 *
 * @param array<string, array<string, mixed>> $store
 * @param list<string>|null $activities  null = all activities
 * @return array<string, mixed>
 */
function explore_aggregate(array $store, ?array $activities, ?int $highlightYear): array
{
    $visited = [];   // "x,y" => [count, firstYear]
    foreach ($store as $tr) {
        if ($activities !== null && !in_array((string)$tr['a'], $activities, true)) {
            continue;
        }
        $yr = $tr['d'] ? (int)substr((string)$tr['d'], 0, 4) : 0;
        foreach ($tr['t'] as $k) {
            if (!isset($visited[$k])) {
                $visited[$k] = [1, $yr];
            } else {
                $visited[$k][0]++;
                if ($yr && ($visited[$k][1] === 0 || $yr < $visited[$k][1])) {
                    $visited[$k][1] = $yr;
                }
            }
        }
    }

    // Cluster: inner tiles (all four neighbours visited), largest 4-connected group
    $inner = [];
    foreach ($visited as $k => $_) {
        [$x, $y] = array_map('intval', explode(',', $k));
        if (isset($visited[($x - 1) . ",$y"], $visited[($x + 1) . ",$y"], $visited["$x," . ($y - 1)], $visited["$x," . ($y + 1)])) {
            $inner[$k] = true;
        }
    }
    $best = [];
    $done = [];
    foreach ($inner as $k => $_) {
        if (isset($done[$k])) {
            continue;
        }
        $group = [];
        $stack = [$k];
        $done[$k] = true;
        while ($stack) {
            $c = array_pop($stack);
            $group[] = $c;
            [$x, $y] = array_map('intval', explode(',', $c));
            foreach ([[$x - 1, $y], [$x + 1, $y], [$x, $y - 1], [$x, $y + 1]] as [$nx, $ny]) {
                $nk = "$nx,$ny";
                if (isset($inner[$nk]) && !isset($done[$nk])) {
                    $done[$nk] = true;
                    $stack[] = $nk;
                }
            }
        }
        if (count($group) > count($best)) {
            $best = $group;
        }
    }

    // Largest square: dp over visited tiles sorted by y, then x
    $keys = array_keys($visited);
    usort($keys, static function ($a, $b) {
        [$ax, $ay] = array_map('intval', explode(',', $a));
        [$bx, $by] = array_map('intval', explode(',', $b));
        return $ay <=> $by ?: $ax <=> $bx;
    });
    $dp = [];
    $sq = ['size' => 0, 'x' => 0, 'y' => 0];
    foreach ($keys as $k) {
        [$x, $y] = array_map('intval', explode(',', $k));
        $v = 1 + min($dp[($x - 1) . ",$y"] ?? 0, $dp["$x," . ($y - 1)] ?? 0, $dp[($x - 1) . ',' . ($y - 1)] ?? 0);
        $dp[$k] = $v;
        if ($v > $sq['size']) {
            // (x, y) is the bottom-right tile; store the top-left one
            $sq = ['size' => $v, 'x' => $x - $v + 1, 'y' => $y - $v + 1];
        }
    }

    $tiles = [];
    $newCount = 0;
    foreach ($visited as $k => [$cnt, $yr]) {
        [$x, $y] = array_map('intval', explode(',', $k));
        $tiles[] = [$x, $y, $cnt, $yr];
        if ($highlightYear !== null && $yr === $highlightYear) {
            $newCount++;
        }
    }

    return [
        'zoom'      => EXPLORE_ZOOM,
        'tiles'     => $tiles,
        'total'     => count($tiles),
        'cluster'   => array_map(static fn($k) => array_map('intval', explode(',', $k)), $best),
        'square'    => $sq,
        'new_count' => $newCount,
    ];
}
