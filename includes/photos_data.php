<?php
declare(strict_types=1);

/**
 * photos_data.php — DB query logic for the photos page.
 *
 * Exports:
 *   load_photos_page_data(PDO $pdo): array
 *
 * Single-track režim: pokud je v URL ?track_id=X (kladné celé číslo),
 * všechny dotazy se omezí jen na fotky té trasy a stránkování se vypne.
 */

/**
 * Load all data needed to render the photos page.
 *
 * Returns an associative array suitable for extract() in the entry point.
 *
 * @param  \PDO $pdo
 * @return array
 */
function load_photos_page_data(\PDO $pdo): array
{
    $isAdmin = !empty($_SESSION['is_admin']);

    // --- Optional single-track filter (?track_id=X) ---
    $filterTrack   = null;
    $filterTrackId = isset($_GET['track_id']) ? (int)$_GET['track_id'] : 0;
    if ($filterTrackId > 0) {
        $st = $pdo->prepare("SELECT id, track_name, filename, date_start FROM tracks WHERE id = :id LIMIT 1");
        $st->execute([':id' => $filterTrackId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            $filterTrack = $row;
        }
    }
    $trackId = $filterTrack ? (int)$filterTrack['id'] : null;

    // --- Helper: assemble WHERE for track_photos queries ---
    // $alias = '' (no JOIN) or 'p' (joined as p)
    $buildWhere = function (string $alias = '') use ($isAdmin, $trackId): string {
        $col = $alias !== '' ? ($alias . '.') : '';
        $parts = [];
        if (!$isAdmin)        $parts[] = "({$col}visible IS NULL OR {$col}visible = 1)";
        if ($trackId !== null) $parts[] = "{$col}track_id = " . (int)$trackId;
        return $parts ? 'WHERE ' . implode(' AND ', $parts) : '';
    };

    // --- Global stats ---
    $statsWhere = $buildWhere();
    $statsRow = $pdo->query("
        SELECT COUNT(*) AS total,
               SUM(lat IS NOT NULL) AS with_gps,
               SUM(track_id IS NOT NULL) AS with_track,
               SUM(virtual_track_id IS NOT NULL) AS with_virtual
        FROM track_photos
        $statsWhere
    ")->fetch(\PDO::FETCH_ASSOC);

    $totalCount  = (int)$statsRow['total'];
    $withGps     = (int)$statsRow['with_gps'];
    $withTrack   = (int)$statsRow['with_track'];
    $withVirtual = (int)$statsRow['with_virtual'];
    // Nepřiřazené = bez GPX trasy I bez virtuální trasy
    $unassigned  = $totalCount - $withTrack - $withVirtual;

    // --- Gallery pagination (vypnuté v single-track režimu) ---
    $GALLERY_PER_PAGE_OPTIONS = [50, 100, 250, 500];
    $galleryPerPage = (int)($_GET['per_page'] ?? 250);
    if (!in_array($galleryPerPage, $GALLERY_PER_PAGE_OPTIONS)) {
        $galleryPerPage = 250;
    }
    $galleryPage = max(1, (int)($_GET['page'] ?? 1));

    // Galerie „Správa" zobrazuje jen GPX trasy + skutečně nepřiřazené —
    // fotky ve virtuální trase mají vlastní záložku, sem nepatří.
    $groupsWhere = $buildWhere('p');
    $groupsWhere = $groupsWhere === ''
        ? 'WHERE p.virtual_track_id IS NULL'
        : $groupsWhere . ' AND p.virtual_track_id IS NULL';

    if ($filterTrack) {
        // Single-track režim: jedna skupina, žádné stránkování.
        $currentPageGroups = [[
            'track_id'       => $trackId,
            'track_name'     => $filterTrack['track_name'],
            'track_filename' => $filterTrack['filename'],
            'date_start'     => $filterTrack['date_start'],
            'cnt'            => $totalCount,
        ]];
        $totalGalleryPages = 1;
        $galleryPage       = 1;
    } else {
        // Standardní režim: skupiny seřazené podle data, stránkované po počtu fotek.
        $groupsStmt = $pdo->query("
            SELECT p.track_id,
                   t.track_name, t.filename AS track_filename, t.date_start,
                   COUNT(*) AS cnt
            FROM track_photos p
            LEFT JOIN tracks t ON t.id = p.track_id
            $groupsWhere
            GROUP BY p.track_id
            ORDER BY t.date_start DESC, p.track_id DESC
        ");
        $allGroups = $groupsStmt->fetchAll(\PDO::FETCH_ASSOC);

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
    }

    // --- Photos for current page (pro single-track režim: všechny fotky té trasy) ---
    $pagePhotos = [];
    if ($filterTrack) {
        $photosStmt = $pdo->prepare("
            SELECT p.id, p.filename, p.orig_name, p.lat, p.lon, p.altitude,
                   p.taken_at, p.width, p.height, p.file_size, p.track_id,
                   p.caption, p.img_direction, p.visible,
                   t.track_name, t.filename AS track_filename, t.date_start
            FROM track_photos p
            LEFT JOIN tracks t ON t.id = p.track_id
            WHERE p.track_id = :tid "
            . ($isAdmin ? '' : 'AND (p.visible IS NULL OR p.visible = 1) ')
          . "ORDER BY p.taken_at DESC, p.id DESC
        ");
        $photosStmt->execute([':tid' => $trackId]);
        $pagePhotos = $photosStmt->fetchAll(\PDO::FETCH_ASSOC);
    } else {
        $pageTrackIds = array_map(fn($g) => $g['track_id'], $currentPageGroups);
        $hasNullGroup = in_array(null, $pageTrackIds, true);
        $nonNullIds   = array_values(array_filter($pageTrackIds, fn($id) => $id !== null));

        if (!empty($nonNullIds) || $hasNullGroup) {
            $parts  = [];
            $params = [];
            if (!empty($nonNullIds)) {
                $parts[]  = 'p.track_id IN (' . implode(',', array_fill(0, count($nonNullIds), '?')) . ')';
                $params   = $nonNullIds;
            }
            if ($hasNullGroup) {
                $parts[] = '(p.track_id IS NULL AND p.virtual_track_id IS NULL)';
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
    }

    // --- Unassigned photos (v single-track režimu irelevantní) ---
    // Skutečně nepřiřazené = bez GPX trasy I bez virtuální trasy.
    $unassignedPhotos = [];
    if (!$filterTrack && $unassigned > 0) {
        $unassignedPhotos = $pdo->query("
            SELECT p.id, p.filename, p.orig_name, p.lat, p.lon, p.taken_at, p.track_id
            FROM track_photos p
            WHERE p.track_id IS NULL AND p.virtual_track_id IS NULL
            ORDER BY p.taken_at DESC, p.id DESC
            LIMIT 500
        ")->fetchAll(\PDO::FETCH_ASSOC);
    }

    // --- Virtuální trasy (samostatná záložka) — seskupené fotky ---
    $virtualGroups = [];
    if (!$filterTrack && $withVirtual > 0) {
        $visV = $isAdmin ? '' : 'AND (p.visible IS NULL OR p.visible = 1)';
        $vrows = $pdo->query("
            SELECT p.id, p.filename, p.orig_name, p.lat, p.lon, p.taken_at, p.caption,
                   p.virtual_track_id,
                   v.name AS vt_name, v.date_start AS vt_date, v.photo_count AS vt_count,
                   v.distance_km AS vt_dist
            FROM track_photos p
            JOIN virtual_tracks v ON v.id = p.virtual_track_id
            WHERE p.virtual_track_id IS NOT NULL $visV
            ORDER BY v.date_start DESC, v.id DESC, p.taken_at ASC, p.id ASC
        ")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($vrows as $r) {
            $key = (int)$r['virtual_track_id'];
            if (!isset($virtualGroups[$key])) {
                $virtualGroups[$key] = [
                    'id'          => $key,
                    'name'        => $r['vt_name'],
                    'date_start'  => $r['vt_date'],
                    'photo_count' => (int)$r['vt_count'],
                    'distance_km' => $r['vt_dist'],
                    'photos'      => [],
                ];
            }
            $virtualGroups[$key]['photos'][] = $r;
        }
    }

    // Tracks for assign select
    $tracksForSelect = $pdo->query("
        SELECT id, track_name, filename, date_start
        FROM   tracks
        ORDER  BY date_start DESC
        LIMIT  500
    ")->fetchAll(\PDO::FETCH_ASSOC);

    // Virtual tracks for assign select (hodnota v selectu = 'v' + id)
    $virtualTracksForSelect = $pdo->query("
        SELECT id, name, date_start
        FROM   virtual_tracks
        ORDER  BY date_start DESC
        LIMIT  500
    ")->fetchAll(\PDO::FETCH_ASSOC);

    // --- Timeline (omezeno v single-track režimu) ---
    $timelineParts = ['p.taken_at IS NOT NULL'];
    if (!$isAdmin)         $timelineParts[] = '(p.visible IS NULL OR p.visible = 1)';
    if ($trackId !== null) $timelineParts[] = 'p.track_id = ' . (int)$trackId;
    $timelineWhere = 'WHERE ' . implode(' AND ', $timelineParts);
    $timelinePhotos = $pdo->query("
        SELECT p.*, t.track_name, t.filename AS track_filename
        FROM track_photos p
        LEFT JOIN tracks t ON t.id = p.track_id
        $timelineWhere
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
        'withVirtual'             => $withVirtual,
        'unassigned'              => $unassigned,
        'virtualGroups'           => $virtualGroups,
        'GALLERY_PER_PAGE_OPTIONS'=> $GALLERY_PER_PAGE_OPTIONS,
        'galleryPerPage'          => $galleryPerPage,
        'galleryPage'             => $galleryPage,
        'totalGalleryPages'       => $totalGalleryPages,
        'currentPageGroups'       => $currentPageGroups,
        'pagePhotos'              => $pagePhotos,
        'unassignedPhotos'        => $unassignedPhotos,
        'tracksForSelect'         => $tracksForSelect,
        'virtualTracksForSelect'  => $virtualTracksForSelect,
        'timeline'                => $timeline,
        'timelinePhotos'          => $timelinePhotos,
        'monthNamesCz'            => $monthNamesCz,
        'currentYear'             => $currentYear,
        'groupedForDisplay'       => $groupedForDisplay,
        'filterTrack'             => $filterTrack,   // null nebo {id,track_name,filename,date_start}
    ];
}
