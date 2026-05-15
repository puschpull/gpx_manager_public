<?php
declare(strict_types=1);

/**
 * photos_data.php — DB query logic for the photos page.
 *
 * Exports:
 *   load_photos_page_data(PDO $pdo): array
 */

/**
 * Load all data needed to render the photos page.
 *
 * Returns an associative array suitable for extract() in the entry point.
 *
 * @param  \PDO $pdo
 * @return array{
 *   totalCount: int,
 *   withGps: int,
 *   withTrack: int,
 *   unassigned: int,
 *   GALLERY_PER_PAGE_OPTIONS: int[],
 *   galleryPerPage: int,
 *   galleryPage: int,
 *   totalGalleryPages: int,
 *   currentPageGroups: array,
 *   pagePhotos: array,
 *   unassignedPhotos: array,
 *   tracksForSelect: array,
 *   timeline: array,
 *   timelinePhotos: array,
 *   monthNamesCz: array<string,string>,
 *   currentYear: string,
 *   groupedForDisplay: array,
 * }
 */
function load_photos_page_data(\PDO $pdo): array
{
    $isAdmin = !empty($_SESSION['is_admin']);

    // --- Global stats ---
    $visibleOnly = $isAdmin ? '' : 'WHERE (visible IS NULL OR visible = 1)';
    $statsRow = $pdo->query("
        SELECT COUNT(*) AS total,
               SUM(lat IS NOT NULL) AS with_gps,
               SUM(track_id IS NOT NULL) AS with_track
        FROM track_photos
        $visibleOnly
    ")->fetch(\PDO::FETCH_ASSOC);

    $totalCount = (int)$statsRow['total'];
    $withGps    = (int)$statsRow['with_gps'];
    $withTrack  = (int)$statsRow['with_track'];
    $unassigned = $totalCount - $withTrack;

    // --- Gallery pagination ---
    $GALLERY_PER_PAGE_OPTIONS = [50, 100, 250, 500];
    $galleryPerPage = (int)($_GET['per_page'] ?? 250);
    if (!in_array($galleryPerPage, $GALLERY_PER_PAGE_OPTIONS)) {
        $galleryPerPage = 250;
    }
    $galleryPage = max(1, (int)($_GET['page'] ?? 1));

    // Groups with photo counts (ordered same as photos)
    $visibleWhere = $isAdmin ? '' : 'WHERE (p.visible IS NULL OR p.visible = 1)';
    $groupsStmt = $pdo->query("
        SELECT p.track_id,
               t.track_name, t.filename AS track_filename, t.date_start,
               COUNT(*) AS cnt
        FROM track_photos p
        LEFT JOIN tracks t ON t.id = p.track_id
        $visibleWhere
        GROUP BY p.track_id
        ORDER BY t.date_start DESC, p.track_id DESC
    ");
    $allGroups = $groupsStmt->fetchAll(\PDO::FETCH_ASSOC);

    // Paginate groups by photo count sum
    $paginatedPages = [[]];
    $curPageIdx     = 0;
    $curPageCount   = 0;
    foreach ($allGroups as $g) {
        if ($curPageCount > 0 && $curPageCount + (int)$g['cnt'] > $galleryPerPage) {
            $curPageIdx++;
            $paginatedPages[$curPageIdx] = [];
            $curPageCount = 0;
        }
        $paginatedPages[$curPageIdx][] = $g;
        $curPageCount += (int)$g['cnt'];
    }
    $totalGalleryPages = max(1, count($paginatedPages));
    $galleryPage       = min($galleryPage, $totalGalleryPages);
    $currentPageGroups = $paginatedPages[$galleryPage - 1] ?? [];

    // Photos for current page
    $pageTrackIds = array_map(fn($g) => $g['track_id'], $currentPageGroups);
    $hasNullGroup = in_array(null, $pageTrackIds, true);
    $nonNullIds   = array_values(array_filter($pageTrackIds, fn($id) => $id !== null));

    $pagePhotos = [];
    if (!empty($nonNullIds) || $hasNullGroup) {
        $parts  = [];
        $params = [];
        if (!empty($nonNullIds)) {
            $parts[]  = 'p.track_id IN (' . implode(',', array_fill(0, count($nonNullIds), '?')) . ')';
            $params   = $nonNullIds;
        }
        if ($hasNullGroup) {
            $parts[] = 'p.track_id IS NULL';
        }

        $visibleCond = $isAdmin ? '' : 'AND (p.visible IS NULL OR p.visible = 1)';
        $photosStmt = $pdo->prepare("
            SELECT p.id, p.filename, p.orig_name, p.lat, p.lon, p.altitude,
                   p.taken_at, p.width, p.height, p.file_size, p.track_id,
                   p.caption, p.img_direction, p.visible,
                   t.track_name, t.filename AS track_filename, t.date_start
            FROM track_photos p
            LEFT JOIN tracks t ON t.id = p.track_id
            WHERE (" . implode(' OR ', $parts) . ") $visibleCond
            ORDER BY p.taken_at DESC, p.id DESC
        ");
        $photosStmt->execute($params);
        $pagePhotos = $photosStmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // Unassigned photos (small query, only when needed)
    $unassignedPhotos = [];
    if ($unassigned > 0) {
        $unassignedPhotos = $pdo->query("
            SELECT p.id, p.filename, p.orig_name, p.lat, p.lon, p.taken_at, p.track_id
            FROM track_photos p
            WHERE p.track_id IS NULL
            ORDER BY p.taken_at DESC, p.id DESC
            LIMIT 500
        ")->fetchAll(\PDO::FETCH_ASSOC);
    }

    // Tracks for assign select
    $tracksForSelect = $pdo->query("
        SELECT id, track_name, filename, date_start
        FROM   tracks
        ORDER  BY date_start DESC
        LIMIT  500
    ")->fetchAll(\PDO::FETCH_ASSOC);

    // Timeline
    $timelineVisible = $isAdmin ? '' : 'AND (p.visible IS NULL OR p.visible = 1)';
    $timelinePhotos = $pdo->query("
        SELECT p.*, t.track_name, t.filename AS track_filename
        FROM track_photos p
        LEFT JOIN tracks t ON t.id = p.track_id
        WHERE p.taken_at IS NOT NULL $timelineVisible
        ORDER BY p.taken_at ASC
    ")->fetchAll(\PDO::FETCH_ASSOC);

    $timeline = [];
    foreach ($timelinePhotos as $tp) {
        $month             = substr($tp['taken_at'], 0, 7);
        $day               = substr($tp['taken_at'], 0, 10);
        $timeline[$month][$day][] = $tp;
    }

    $monthNamesCz = [
        '01' => 'Leden',   '02' => 'Únor',     '03' => 'Březen',
        '04' => 'Duben',   '05' => 'Květen',    '06' => 'Červen',
        '07' => 'Červenec','08' => 'Srpen',     '09' => 'Září',
        '10' => 'Říjen',   '11' => 'Listopad',  '12' => 'Prosinec',
    ];
    $currentYear = date('Y');

    // Pre-group photos for gallery display
    $groupedForDisplay = [];
    foreach ($pagePhotos as $p) {
        $key = $p['track_id'] ? 'track_' . $p['track_id'] : 'unassigned';
        if (!isset($groupedForDisplay[$key])) {
            $groupedForDisplay[$key] = [
                'track_id'   => $p['track_id'],
                'track_name' => $p['track_name'] ?: $p['track_filename'],
                'date_start' => $p['date_start'],
                'photos'     => [],
            ];
        }
        $groupedForDisplay[$key]['photos'][] = $p;
    }

    return [
        'totalCount'              => $totalCount,
        'withGps'                 => $withGps,
        'withTrack'               => $withTrack,
        'unassigned'              => $unassigned,
        'GALLERY_PER_PAGE_OPTIONS'=> $GALLERY_PER_PAGE_OPTIONS,
        'galleryPerPage'          => $galleryPerPage,
        'galleryPage'             => $galleryPage,
        'totalGalleryPages'       => $totalGalleryPages,
        'currentPageGroups'       => $currentPageGroups,
        'pagePhotos'              => $pagePhotos,
        'unassignedPhotos'        => $unassignedPhotos,
        'tracksForSelect'         => $tracksForSelect,
        'timeline'                => $timeline,
        'timelinePhotos'          => $timelinePhotos,
        'monthNamesCz'            => $monthNamesCz,
        'currentYear'             => $currentYear,
        'groupedForDisplay'       => $groupedForDisplay,
    ];
}
