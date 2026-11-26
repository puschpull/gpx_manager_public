<?php
declare(strict_types=1);

/**
 * year_data.php — data for the year summary page (year.php).
 *
 * Everything is computed from the tracks of one calendar year. Date filters
 * use a range (>= Jan 1, < Jan 1 next year) so the date_start index is used.
 * Records count only foot activities, the same rule as on stats.php.
 *
 * Expects $pdo. Produces $yr (array) for includes/year_view.php.
 */

/** Years that have at least one track, newest first. @return list<int> */
function year_list(\PDO $pdo): array
{
    $years = $pdo->query("
        SELECT DISTINCT YEAR(date_start) AS y FROM tracks
        WHERE date_start IS NOT NULL ORDER BY y DESC
    ")->fetchAll(\PDO::FETCH_COLUMN);
    return array_map('intval', $years);
}

/** [from, to) for a calendar year as 'Y-m-d H:i:s'. @return array{0: string, 1: string} */
function year_range(int $year): array
{
    return [sprintf('%04d-01-01 00:00:00', $year), sprintf('%04d-01-01 00:00:00', $year + 1)];
}

/** Totals of one year. @return array<string, mixed> */
function year_totals(\PDO $pdo, int $year): array
{
    [$from, $to] = year_range($year);
    $st = $pdo->prepare("
        SELECT COUNT(*)                        AS tracks,
               COALESCE(SUM(distance_km), 0)   AS km,
               COALESCE(SUM(ascent), 0)        AS ascent,
               COALESCE(SUM(duration), 0)      AS duration,
               COALESCE(MAX(elevation_max), 0) AS top_elev,
               COUNT(DISTINCT DATE(date_start)) AS days,
               COUNT(CASE WHEN is_favorite = 1 THEN 1 END) AS favs
        FROM tracks
        WHERE date_start >= ? AND date_start < ?
    ");
    $st->execute([$from, $to]);
    return $st->fetch(\PDO::FETCH_ASSOC) ?: [];
}

/**
 * Builds everything the view needs.
 *
 * @return array<string, mixed>
 */
function year_build(\PDO $pdo, int $year, bool $withPhotos): array
{
    [$from, $to] = year_range($year);

    $totals = year_totals($pdo, $year);
    $prev   = year_totals($pdo, $year - 1);

    // Monthly km (this and previous year) for the chart
    $monthly = static function (int $y) use ($pdo): array {
        [$f, $t] = year_range($y);
        $st = $pdo->prepare("
            SELECT MONTH(date_start) AS m, SUM(distance_km) AS km, COUNT(*) AS cnt, SUM(ascent) AS asc_m
            FROM tracks WHERE date_start >= ? AND date_start < ?
            GROUP BY MONTH(date_start)
        ");
        $st->execute([$f, $t]);
        $km = array_fill(1, 12, 0.0);
        $cnt = array_fill(1, 12, 0);
        $asc = array_fill(1, 12, 0.0);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $km[(int)$r['m']]  = round((float)$r['km'], 1);
            $cnt[(int)$r['m']] = (int)$r['cnt'];
            $asc[(int)$r['m']] = round((float)$r['asc_m']);
        }
        return ['km' => array_values($km), 'count' => array_values($cnt), 'ascent' => array_values($asc)];
    };
    $months     = $monthly($year);
    $monthsPrev = $monthly($year - 1);

    // Busiest month by km
    $bestMonth = null;
    $maxKm = 0.0;
    foreach ($months['km'] as $i => $km) {
        if ($km > $maxKm) {
            $maxKm = $km;
            $bestMonth = $i + 1;
        }
    }

    // Activities
    $st = $pdo->prepare("
        SELECT activity_type, COUNT(*) AS cnt, SUM(distance_km) AS km, SUM(ascent) AS ascent
        FROM tracks
        WHERE date_start >= ? AND date_start < ? AND activity_type IS NOT NULL AND activity_type <> ''
        GROUP BY activity_type ORDER BY cnt DESC
    ");
    $st->execute([$from, $to]);
    $activities = $st->fetchAll(\PDO::FETCH_ASSOC);

    // Highlights — foot activities only (see foot_activities())
    $foot   = foot_activities();
    $footPh = implode(',', array_fill(0, count($foot), '?'));
    $top = static function (string $order, int $limit) use ($pdo, $from, $to, $foot, $footPh): array {
        $st = $pdo->prepare("
            SELECT id, filename, track_name, alt_title, place_name, date_start, distance_km,
                   ascent, elevation_max, duration, difficulty, activity_type, is_favorite
            FROM tracks
            WHERE date_start >= ? AND date_start < ? AND activity_type IN ($footPh)
            ORDER BY $order LIMIT $limit
        ");
        $st->execute(array_merge([$from, $to], $foot));
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    };
    $longest    = $top('distance_km DESC', 5);
    $mostAscent = $top('ascent DESC', 1);
    $highest    = $top('elevation_max DESC', 1);

    // Favourites of the year (any activity)
    $st = $pdo->prepare("
        SELECT id, filename, track_name, alt_title, place_name, date_start, distance_km, activity_type
        FROM tracks
        WHERE date_start >= ? AND date_start < ? AND is_favorite = 1
        ORDER BY date_start ASC LIMIT 24
    ");
    $st->execute([$from, $to]);
    $favorites = $st->fetchAll(\PDO::FETCH_ASSOC);

    // Places visited for the first time this year (by place_name)
    $st = $pdo->prepare("
        SELECT t.place_name, MIN(t.date_start) AS first_at
        FROM tracks t
        WHERE t.place_name IS NOT NULL AND t.place_name <> ''
        GROUP BY t.place_name
        HAVING first_at >= ? AND first_at < ?
        ORDER BY first_at ASC
    ");
    $st->execute([$from, $to]);
    $newPlaces = $st->fetchAll(\PDO::FETCH_ASSOC);

    // Photos: count + an evenly spread sample of visible photos from the year
    $photoCount = 0;
    $photos = [];
    if ($withPhotos) {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM track_photos
            WHERE taken_at >= ? AND taken_at < ? AND (visible IS NULL OR visible = 1)
        ");
        $st->execute([$from, $to]);
        $photoCount = (int)$st->fetchColumn();

        if ($photoCount > 0) {
            $st = $pdo->prepare("
                SELECT p.id, p.filename, p.taken_at, p.caption, p.track_id
                FROM track_photos p
                WHERE p.taken_at >= ? AND p.taken_at < ? AND (p.visible IS NULL OR p.visible = 1)
                ORDER BY p.taken_at ASC
            ");
            $st->execute([$from, $to]);
            $all  = $st->fetchAll(\PDO::FETCH_ASSOC);
            $want = 12;
            $n    = count($all);
            if ($n <= $want) {
                $photos = $all;
            } else {
                for ($i = 0; $i < $want; $i++) {
                    $photos[] = $all[(int)floor(($i + 0.5) * $n / $want)];
                }
            }
        }
    }

    return [
        'year'        => $year,
        'totals'      => $totals,
        'prev'        => $prev,
        'months'      => $months,
        'months_prev' => $monthsPrev,
        'best_month'  => $bestMonth,
        'activities'  => $activities,
        'longest'     => $longest,
        'most_ascent' => $mostAscent[0] ?? null,
        'highest'     => $highest[0] ?? null,
        'favorites'   => $favorites,
        'new_places'  => $newPlaces,
        'photo_count' => $photoCount,
        'photos'      => $photos,
    ];
}
