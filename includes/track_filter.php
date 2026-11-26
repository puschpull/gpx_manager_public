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
/**
 * Splits a search query into terms: "quoted phrase" stays one term,
 * a leading minus (-word) negates the term. At most 8 terms.
 *
 * @return list<array{0: string, 1: bool}>  [term, negate]
 */
function search_terms(string $q): array
{
    preg_match_all('/(-?)"([^"]+)"|(-?)(\S+)/u', $q, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $hit) {
        if (isset($hit[4]) && $hit[4] !== '') {
            $neg  = $hit[3] === '-';
            $term = $hit[4];
        } else {
            $neg  = $hit[1] === '-';
            $term = $hit[2];
        }
        $term = trim($term);
        // A lone "-" or a negated term only makes sense with something to keep
        if ($term === '' || $term === '-') {
            continue;
        }
        $out[] = [$term, $neg];
        if (count($out) >= 8) {
            break;
        }
    }
    // Only negative terms → treat them as positive (otherwise "-x" lists everything else)
    if ($out && !array_filter($out, static fn($t) => !$t[1])) {
        $out = array_map(static fn($t) => [$t[0], false], $out);
    }
    return $out;
}

function buildFilterSQL(?array $filters = null, string $prefix = ''): array
{
    $filters = $filters ?? $_GET;
    $params  = [];
    $clauses = [];

    // --- Full-text search ---
    // Words are ANDed (order does not matter), each word may hit any field:
    // name, title, place, note, file, device, category, photo caption or the
    // published travelogue. "quoted phrase" = one term, -word = must not match.
    // LIKE wildcards in user input are escaped (ESCAPE '!').
    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
        $hiddenPhotos = !empty($_SESSION['is_admin']) ? '' : ' AND (tp.visible IS NULL OR tp.visible = 1)';
        foreach (search_terms($q) as $i => [$term, $negate]) {
            $p = ":q$i";
            $esc = " ESCAPE '!'";
            $any = "({$prefix}track_name LIKE $p$esc
                OR {$prefix}alt_title LIKE $p$esc
                OR {$prefix}place_name LIKE $p$esc
                OR {$prefix}note LIKE $p$esc
                OR {$prefix}filename LIKE $p$esc
                OR {$prefix}device LIKE $p$esc
                OR EXISTS (
                    SELECT 1 FROM track_categories tc
                    JOIN categories c ON c.id = tc.category_id
                    WHERE tc.track_id = {$prefix}id AND c.name LIKE $p$esc
                )
                OR EXISTS (
                    SELECT 1 FROM track_photos tp
                    WHERE tp.track_id = {$prefix}id AND tp.caption LIKE $p$esc$hiddenPhotos
                )
                OR EXISTS (
                    SELECT 1 FROM track_stories ts
                    WHERE ts.track_id = {$prefix}id AND ts.is_published = 1 AND ts.status = 'done'
                      AND ts.story LIKE $p$esc
                ))";
            $clauses[] = $negate ? "NOT $any" : $any;
            $params[$p] = '%' . strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
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

    /* --- Radar (srážkové snímky ČHMÚ) ---
       Snímky jsou soubory v uploads/radar/, v databázi o nich není záznam —
       filtr proto pracuje se seznamem id z adresáře. Tras s radarem jsou
       jednotky až desítky, takže IN (...) je v pohodě.
         have = má snímky   none = nemá   todo = nemá, ale ještě jde stáhnout */
    $radar = trim($filters['radar'] ?? '');
    if ($radar !== '') {
        require_once __DIR__ . '/radar_helper.php';
        $ids = array_keys(radar_counts());
        $list = $ids ? implode(',', array_map('intval', $ids)) : '0';

        if ($radar === 'have') {
            $clauses[] = "{$prefix}id IN ($list)";
        } elseif ($radar === 'none') {
            $clauses[] = "{$prefix}id NOT IN ($list)";
        } elseif ($radar === 'todo') {
            $clauses[] = "({$prefix}id NOT IN ($list)
                AND {$prefix}date_start IS NOT NULL
                AND {$prefix}date_start >= :radar_since)";
            $params[':radar_since'] = date('Y-m-d H:i:s', time() - RADAR_ARCHIVE_D * 86400);
        }
    }

    /* --- Cestopis ---
       have = má zveřejněný cestopis   none = nemá.
       Kdo cestopisy nevidí (funkce vypnutá / návštěvník bez povolení),
       tomu se filtr tiše ignoruje — jinak by z výsledků poznal, které trasy ho mají. */
    $story = trim($filters['story'] ?? '');
    if ($story === 'have' || $story === 'none') {
        require_once __DIR__ . '/story_link.php';
        if (story_visible_to_user()) {
            $exists = story_exists_sql("{$prefix}id");
            $clauses[] = $story === 'have' ? $exists : "NOT $exists";
        }
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
