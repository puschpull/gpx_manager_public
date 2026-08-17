<?php
declare(strict_types=1);

/**
 * ===========================================================
 *  track_filter.php — Dynamic SQL filter builder
 *  Translates GET/POST filter params into a WHERE clause +
 *  bound parameter array for PDO prepared statements.
 * ===========================================================
 */

/**
 * Builds a SQL range condition with optional inversion.
 *
 * Normal:   col >= min AND col <= max
 * Inverted: col < min  OR  col > max
 *
 * @param string      $col    SQL column expression (may include table prefix).
 * @param string|null $min    Minimum value string (empty string = no bound).
 * @param string|null $max    Maximum value string (empty string = no bound).
 * @param bool        $inv    Whether to invert the range.
 * @param array       $params Bound-parameter array, modified in-place.
 * @param string      $minKey PDO placeholder name for min (without leading colon).
 * @param string      $maxKey PDO placeholder name for max (without leading colon).
 *
 * @return string|null SQL fragment, or null if neither bound is set.
 */
function buildRangeSQL(string $col, ?string $min, ?string $max, bool $inv, array &$params, string $minKey, string $maxKey): ?string
{
    $hasMin = ($min !== null && $min !== '');
    $hasMax = ($max !== null && $max !== '');

    if (!$hasMin && !$hasMax) return null;

    if (!$inv) {
        $parts = [];
        if ($hasMin) { $parts[] = "$col >= :$minKey"; $params[":$minKey"] = (float)$min; }
        if ($hasMax) { $parts[] = "$col <= :$maxKey"; $params[":$maxKey"] = (float)$max; }
        return implode(' AND ', $parts);
    } else {
        $parts = [];
        if ($hasMin) { $parts[] = "$col < :$minKey";  $params[":$minKey"] = (float)$min; }
        if ($hasMax) { $parts[] = "$col > :$maxKey";  $params[":$maxKey"] = (float)$max; }
        if (count($parts) === 2) return '(' . implode(' OR ', $parts) . ')';
        return $parts[0] ?? null;
    }
}

/**
 * Builds a WHERE clause from filter parameters.
 *
 * @param array|null $filters  Source of filter values. Defaults to $_GET when null.
 * @param string     $prefix   Optional table alias prefix for column names (e.g. 't.').
 *
 * @return array{where: string, params: array<string, mixed>}
 *   'where'  — SQL fragment suitable for appending after WHERE (empty string if no filters).
 *   'params' — Associative array of bound parameters for PDO execute / bindValue.
 */
function buildFilterSQL(?array $filters = null, string $prefix = ''): array
{
    $filters = $filters ?? $_GET;
    $params  = [];
    $clauses = [];

    // --- Full-text search ---
    $q = trim($filters['q'] ?? '');
    if ($q !== '') {
        $clauses[] = "({$prefix}track_name LIKE :q
            OR {$prefix}alt_title LIKE :q
            OR {$prefix}place_name LIKE :q
            OR {$prefix}note LIKE :q
            OR {$prefix}filename LIKE :q
            OR {$prefix}device LIKE :q
            OR EXISTS (
                SELECT 1 FROM track_categories tc
                JOIN categories c ON c.id = tc.category_id
                WHERE tc.track_id = {$prefix}id AND c.name LIKE :q
            ))";
        $params[':q'] = "%$q%";
    }

    // --- Favourites ---
    $fav = trim($filters['fav_only'] ?? '');
    if ($fav === '1') {
        $clauses[] = "{$prefix}is_favorite = 1";
    }

    // --- Difficulty ---
    $diff_min = trim($filters['diff_min'] ?? '');
    $diff_max = trim($filters['diff_max'] ?? '');
    if ($diff_min !== '') {
        $clauses[] = "{$prefix}difficulty >= :diff_min";
        $params[':diff_min'] = (int)$diff_min;
    }
    if ($diff_max !== '') {
        $clauses[] = "{$prefix}difficulty <= :diff_max";
        $params[':diff_max'] = (int)$diff_max;
    }

    // --- Activity type ---
    $activity = trim($filters['activity'] ?? '');
    if ($activity !== '') {
        $clauses[] = "{$prefix}activity_type = :activity";
        $params[':activity'] = $activity;
    }

    // --- Place (obec u startu trasy) ---
    // Přesná shoda, ne LIKE: hodnota jde z rozbalovacího seznamu naplněného
    // skutečnými hodnotami z databáze. Na hledání podtextu je pole „q".
    $place = trim($filters['place'] ?? '');
    if ($place !== '') {
        $clauses[] = "{$prefix}place_name = :place";
        $params[':place'] = $place;
    }

    // --- Colour ---
    $color = trim($filters['color'] ?? '');
    if ($color !== '') {
        $clauses[] = "{$prefix}color = :color";
        $params[':color'] = $color;
    }

    // --- Categories ---
    $cat = $filters['cat'] ?? [];
    if (!is_array($cat)) $cat = [$cat];
    $cat = array_values(array_filter(array_map('trim', $cat)));
    if (!empty($cat)) {
        $catMode = ($filters['cat_mode'] ?? 'or') === 'and' ? 'and' : 'or';

        if ($catMode === 'or') {
            // OR — tracks that have at least one of the selected categories
            $placeholders = [];
            foreach ($cat as $i => $_) $placeholders[] = ":cat{$i}";
            $in        = implode(',', $placeholders);
            $clauses[] = "EXISTS (
                SELECT 1 FROM track_categories tc
                JOIN categories c ON c.id = tc.category_id
                WHERE tc.track_id = {$prefix}id AND c.name IN ($in)
            )";
            foreach ($cat as $i => $v) $params[":cat{$i}"] = $v;
        } else {
            // AND — tracks that have ALL selected categories
            $andClauses = [];
            foreach ($cat as $i => $v) {
                $andClauses[] = "EXISTS (
                    SELECT 1 FROM track_categories tc
                    JOIN categories c ON c.id = tc.category_id
                    WHERE tc.track_id = {$prefix}id AND c.name = :cat{$i}
                )";
                $params[":cat{$i}"] = $v;
            }
            $clauses[] = implode(' AND ', $andClauses);
        }
    }

    // --- Date range ---
    $date_from = trim($filters['date_from'] ?? '');
    $date_to   = trim($filters['date_to']   ?? '');
    $date_inv  = !empty($filters['date_inv']);
    if ($date_from !== '' || $date_to !== '') {
        // Range pattern avoids DATE() wrapper that kills idx_tracks_date index (DB-6).
        if (!$date_inv) {
            if ($date_from !== '') { $clauses[] = "{$prefix}date_start >= :date_from";                          $params[':date_from'] = $date_from; }
            if ($date_to   !== '') { $clauses[] = "{$prefix}date_start < DATE_ADD(:date_to, INTERVAL 1 DAY)";  $params[':date_to']   = $date_to; }
        } else {
            $parts = [];
            if ($date_from !== '') { $parts[] = "{$prefix}date_start < :date_from";                          $params[':date_from'] = $date_from; }
            if ($date_to   !== '') { $parts[] = "{$prefix}date_start >= DATE_ADD(:date_to, INTERVAL 1 DAY)"; $params[':date_to']   = $date_to; }
            if (count($parts) === 2) $clauses[] = '(' . implode(' OR ', $parts) . ')';
            elseif ($parts)         $clauses[] = $parts[0];
        }
    }

    // --- Numeric range filters with inversion ---
    $ranges = [
        ['col' => "{$prefix}distance_km",    'min' => 'dist_min',          'max' => 'dist_max',          'inv' => 'dist_inv'],
        ['col' => "{$prefix}ascent",         'min' => 'ascent_min',        'max' => 'ascent_max',        'inv' => 'ascent_inv'],
        ['col' => "{$prefix}descent",        'min' => 'descent_min',       'max' => 'descent_max',       'inv' => 'descent_inv'],
        ['col' => "{$prefix}speed_avg",      'min' => 'savg_min',          'max' => 'savg_max',          'inv' => 'savg_inv'],
        ['col' => "{$prefix}speed_avg_total",'min' => 'savg_total_min',    'max' => 'savg_total_max',    'inv' => 'savg_total_inv'],
        ['col' => "{$prefix}speed_max",      'min' => 'smax_min',          'max' => 'smax_max',          'inv' => 'smax_inv'],
        ['col' => "{$prefix}elevation_min",  'min' => 'elevation_min_val', 'max' => 'elevation_min_max', 'inv' => 'elevation_min_inv'],
        ['col' => "{$prefix}elevation_max",  'min' => 'elevation_max_val', 'max' => 'elevation_max_max', 'inv' => 'elevation_max_inv'],
    ];

    // Deduplication guard (prevents duplicate placeholder names)
    $seen = [];
    foreach ($ranges as $r) {
        $key = $r['min'] . '_' . $r['max'];
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $min = trim($filters[$r['min']] ?? '');
        $max = trim($filters[$r['max']] ?? '');
        $inv = !empty($filters[$r['inv']]);

        $sql = buildRangeSQL($r['col'], $min ?: null, $max ?: null, $inv, $params, $r['min'], $r['max']);
        if ($sql !== null) $clauses[] = $sql;
    }

    return [
        'where'  => implode(' AND ', $clauses),
        'params' => $params,
    ];
}
