<?php
declare(strict_types=1);

/**
 * collections_helper.php — track collections (migration 0022).
 *
 * A collection is a named list of tracks across categories ("Lužické hory 2026").
 * Visitors see only collections with is_public = 1, and only when the page
 * 'collections' is in visible_pages. Writes: api/collections/* (admin + CSRF).
 *
 * Read helpers swallow a missing table (PDOException) and return empty data,
 * so the track detail keeps working even before the migration ran.
 */

/** Can the current request see collections at all? */
function collections_visible(): bool
{
    if (!empty($_SESSION['is_admin'])) {
        return true;
    }
    return in_array('collections', (array)get_app_config('visible_pages', all_pages()), true);
}

/**
 * All collections with summary numbers, newest first.
 *
 * @return list<array<string, mixed>>
 */
function collections_list(\PDO $pdo, bool $isAdmin): array
{
    try {
        $rows = $pdo->query("
            SELECT c.id, c.name, c.description, c.is_public, c.created_at,
                   COUNT(t.id)              AS track_count,
                   COALESCE(SUM(t.distance_km), 0) AS km,
                   COALESCE(SUM(t.ascent), 0)      AS ascent,
                   MIN(t.date_start)        AS first_date,
                   MAX(t.date_start)        AS last_date
            FROM collections c
            LEFT JOIN collection_tracks ct ON ct.collection_id = c.id
            LEFT JOIN tracks t ON t.id = ct.track_id
            " . ($isAdmin ? '' : 'WHERE c.is_public = 1') . "
            GROUP BY c.id, c.name, c.description, c.is_public, c.created_at
            ORDER BY COALESCE(MAX(t.date_start), c.created_at) DESC, c.id DESC
        ")->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        return [];
    }

    // Cover = map thumbnail of the longest track in the collection
    $cover = $pdo->prepare("
        SELECT t.filename FROM collection_tracks ct JOIN tracks t ON t.id = ct.track_id
        WHERE ct.collection_id = ? ORDER BY t.distance_km DESC LIMIT 1
    ");
    foreach ($rows as &$r) {
        $cover->execute([(int)$r['id']]);
        $fn = $cover->fetchColumn();
        $r['cover'] = null;
        if (is_string($fn) && $fn !== '') {
            $thumb = preg_replace('/\.gpx$/i', '', $fn) . '.png';
            try {
                if (is_file(uploads_fs('thumbs/' . $thumb))) {
                    $r['cover'] = thumb_url($thumb);
                }
            } catch (\RuntimeException $e) {
                // unsafe filename → no cover
            }
        }
    }
    unset($r);
    return $rows;
}

/** One collection or null (visitors: only public). @return array<string, mixed>|null */
function collection_get(\PDO $pdo, int $id, bool $isAdmin): ?array
{
    try {
        $st = $pdo->prepare("SELECT id, name, description, is_public, created_at FROM collections WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        return null;
    }
    if (!$row || (!$isAdmin && (int)$row['is_public'] !== 1)) {
        return null;
    }
    return $row;
}

/**
 * Tracks of a collection in their order (position, then date).
 *
 * @return list<array<string, mixed>>
 */
function collection_tracks(\PDO $pdo, int $id): array
{
    $st = $pdo->prepare("
        SELECT t.id, t.filename, t.track_name, t.alt_title, t.place_name, t.date_start, t.distance_km,
               t.ascent, t.duration, t.difficulty, t.activity_type, t.is_favorite, ct.position
        FROM collection_tracks ct JOIN tracks t ON t.id = ct.track_id
        WHERE ct.collection_id = ?
        ORDER BY ct.position ASC, t.date_start ASC
    ");
    $st->execute([$id]);
    return $st->fetchAll(\PDO::FETCH_ASSOC);
}

/**
 * Collections a track belongs to (visitors: only public ones).
 *
 * @return list<array{id: int, name: string, is_public: int}>
 */
function collections_of_track(\PDO $pdo, int $trackId, bool $isAdmin): array
{
    try {
        $st = $pdo->prepare("
            SELECT c.id, c.name, c.is_public
            FROM collection_tracks ct JOIN collections c ON c.id = ct.collection_id
            WHERE ct.track_id = ?" . ($isAdmin ? '' : ' AND c.is_public = 1') . "
            ORDER BY c.name
        ");
        $st->execute([$trackId]);
        return array_map(static fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'is_public' => (int)$r['is_public']],
            $st->fetchAll(\PDO::FETCH_ASSOC));
    } catch (\PDOException $e) {
        return [];
    }
}

/** All collections as id => name (admin pickers). @return array<int, string> */
function collections_names(\PDO $pdo): array
{
    try {
        $out = [];
        foreach ($pdo->query("SELECT id, name FROM collections ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['id']] = (string)$r['name'];
        }
        return $out;
    } catch (\PDOException $e) {
        return [];
    }
}
