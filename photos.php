<?php
/**
 * GPX Manager — Správa fotografií
 * Upload fotek s GPS, auto-přiřazení k trasám, mapa vrstva
 */
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/photo_helper.php';

// Veřejné read-only AJAX endpointy (přístupné i návštěvníkům)
$_publicAjax = isset($_GET['ajax']) && in_array($_GET['ajax'], ['track', 'bounds']);

// Správa fotek a HTML stránka — admin nebo povolená stránka pro návštěvníky
$_isAdmin = !empty($_SESSION['is_admin']);
if (!$_isAdmin && !$_publicAjax) {
    $visiblePages = get_app_config('visible_pages', ['stats','calendar','heatmap','map_search','nearby','filter','compare','settings']);
    if (!in_array('photos', $visiblePages)) {
        header('Location: index.php');
        exit;
    }
}
unset($_publicAjax);

/* ===== Theme ===== */
$allThemes = ['classic','dark','darkblue','darkgreen','blue','green','minimal','lightgray','brown'];
$available_themes = get_app_config('allowed_themes', $allThemes);
$theme = $_GET['theme'] ?? ($_COOKIE['theme'] ?? 'classic');
if (!in_array($theme, $available_themes)) $theme = $available_themes[0] ?? 'classic';
$allowedLangs = get_app_config('allowed_langs', ['cs','en','de','sk','es','fr','pl','it']);
if (isset($_GET['theme'])) setcookie('theme', $theme, time() + 365 * 24 * 3600, '/');

/* =====================================================================
   AJAX — upload fotek
   ===================================================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'upload') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_FILES['photos'])) {
        echo json_encode(['error' => 'Žádné soubory.']);
        exit;
    }

    $results = [];
    $files   = $_FILES['photos'];
    $count   = is_array($files['name']) ? count($files['name']) : 1;

    // Normalizace na pole (pro multi-upload)
    if (!is_array($files['name'])) {
        foreach ($files as $k => $v) $files[$k] = [$v];
    }

    // Limit na upload
    set_time_limit(300);
    ini_set('memory_limit', '256M');

    for ($i = 0; $i < $count; $i++) {
        $origName = $files['name'][$i];
        $tmpPath  = $files['tmp_name'][$i];
        $error    = $files['error'][$i];
        $size     = (int)$files['size'][$i];

        if ($error !== UPLOAD_ERR_OK) {
            $results[] = ['file' => $origName, 'ok' => false, 'msg' => 'Chyba uploadu (' . $error . ')'];
            continue;
        }

        // Detekce MIME
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        // ── ZIP soubor ──────────────────────────────────────────────
        $isZip = in_array($mime, ['application/zip','application/x-zip','application/x-zip-compressed'])
              || strtolower(pathinfo($origName, PATHINFO_EXTENSION)) === 'zip';

        if ($isZip) {
            if (!class_exists('ZipArchive')) {
                $results[] = ['file' => $origName, 'ok' => false, 'msg' => 'PHP ZipArchive není dostupný na serveru'];
                continue;
            }
            $zip = new ZipArchive();
            if ($zip->open($tmpPath) !== true) {
                $results[] = ['file' => $origName, 'ok' => false, 'msg' => 'Nepodařilo se otevřít ZIP soubor'];
                continue;
            }

            $tmpDir = sys_get_temp_dir() . '/gpx_photos_' . uniqid('', true);
            mkdir($tmpDir, 0755, true);
            $zip->extractTo($tmpDir);
            $zip->close();

            $imageExts = ['jpg', 'jpeg', 'png', 'webp'];
            $imgLimit  = 200;
            $imgCount  = 0;
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if (!$f->isFile()) continue;
                $ext = strtolower($f->getExtension());
                if (!in_array($ext, $imageExts)) continue;
                if ($imgCount >= $imgLimit) {
                    $results[] = ['ok' => false, 'file' => '…', 'msg' => "Limit {$imgLimit} fotek/ZIP — zbytek přeskočen"];
                    break;
                }
                $results[] = process_single_photo($f->getPathname(), $f->getFilename(), (int)$f->getSize());
                $imgCount++;
            }

            _cleanup_dir($tmpDir);
            continue; // přeskočit běžné zpracování tohoto souboru
        }

        // ── Jednotlivá fotka ────────────────────────────────────────
        $results[] = process_single_photo($tmpPath, $origName, $size);
    }

    echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =====================================================================
   AJAX — fotky pro detail mapy (podle track_id)
   ===================================================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'track') {
    header('Content-Type: application/json; charset=utf-8');

    $trackId = (int)($_GET['id'] ?? 0);
    if (!$trackId) {
        echo json_encode(['photos' => []]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, filename, lat, lon, altitude, taken_at, width, height, caption, img_direction
        FROM   track_photos
        WHERE  track_id = ?
          AND  lat IS NOT NULL
          AND  lon IS NOT NULL
          AND  (visible IS NULL OR visible = 1)
        ORDER  BY taken_at ASC
    ");
    $stmt->execute([$trackId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $photos = array_map(fn($r) => [
        'id'            => (int)$r['id'],
        'thumb_url'     => photo_thumb_url($r['filename']),
        'full_url'      => photo_full_url($r['filename']),
        'lat'           => (float)$r['lat'],
        'lon'           => (float)$r['lon'],
        'altitude'      => $r['altitude'] !== null ? round((float)$r['altitude']) : null,
        'taken_at'      => $r['taken_at'],
        'width'         => $r['width'] ? (int)$r['width'] : null,
        'height'        => $r['height'] ? (int)$r['height'] : null,
        'caption'       => $r['caption'],
        'img_direction' => $r['img_direction'] !== null ? (float)$r['img_direction'] : null,
    ], $rows);

    echo json_encode(['photos' => $photos], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =====================================================================
   AJAX — fotky v oblasti (bounds) pro heatmap / map_search / filter
   ===================================================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'bounds') {
    header('Content-Type: application/json; charset=utf-8');

    $minLat = (float)($_GET['minlat'] ?? 0);
    $maxLat = (float)($_GET['maxlat'] ?? 0);
    $minLon = (float)($_GET['minlon'] ?? 0);
    $maxLon = (float)($_GET['maxlon'] ?? 0);

    if ($minLat == 0 && $maxLat == 0) {
        echo json_encode(['photos' => []]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, filename, lat, lon, altitude, taken_at
        FROM   track_photos
        WHERE  lat BETWEEN :minlat AND :maxlat
          AND  lon BETWEEN :minlon AND :maxlon
        ORDER  BY taken_at ASC
        LIMIT  200
    ");
    $stmt->execute([
        ':minlat' => $minLat, ':maxlat' => $maxLat,
        ':minlon' => $minLon, ':maxlon' => $maxLon,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $photos = array_map(fn($r) => [
        'id'        => (int)$r['id'],
        'thumb_url' => photo_thumb_url($r['filename']),
        'full_url'  => photo_full_url($r['filename']),
        'lat'       => (float)$r['lat'],
        'lon'       => (float)$r['lon'],
        'taken_at'  => $r['taken_at'],
    ], $rows);

    echo json_encode(['photos' => $photos], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =====================================================================
   AJAX — přiřazení fotky k trase
   ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'assign') {
    header('Content-Type: application/json; charset=utf-8');

    $photoId = (int)($_POST['photo_id'] ?? 0);
    $trackId = $_POST['track_id'] !== '' ? (int)$_POST['track_id'] : null;

    if (!$photoId) {
        echo json_encode(['ok' => false, 'msg' => 'Chybí photo_id']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE track_photos SET track_id = ? WHERE id = ?");
    $stmt->execute([$trackId, $photoId]);

    echo json_encode(['ok' => true]);
    exit;
}

/* =====================================================================
   AJAX — smazání fotky
   ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'delete') {
    header('Content-Type: application/json; charset=utf-8');

    $photoId = (int)($_POST['photo_id'] ?? 0);
    if (!$photoId) {
        echo json_encode(['ok' => false, 'msg' => 'Chybí photo_id']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT filename FROM track_photos WHERE id = ?");
    $stmt->execute([$photoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        @unlink(uploads_fs('photos/' . $row['filename']));
        @unlink(uploads_fs('photos/thumbs/' . $row['filename']));
        $pdo->prepare("DELETE FROM track_photos WHERE id = ?")->execute([$photoId]);
    }

    echo json_encode(['ok' => true]);
    exit;
}

/* =====================================================================
   AJAX — uložení popisku fotky (caption) — B2
   ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'update_caption') {
    header('Content-Type: application/json; charset=utf-8');

    $photoId = (int)($_POST['photo_id'] ?? 0);
    $caption = trim($_POST['caption'] ?? '');

    if (!$photoId) {
        echo json_encode(['ok' => false, 'msg' => 'Chybí photo_id']);
        exit;
    }

    $pdo->prepare("UPDATE track_photos SET caption = ? WHERE id = ?")
        ->execute([$caption !== '' ? $caption : null, $photoId]);

    echo json_encode(['ok' => true]);
    exit;
}

/* =====================================================================
   AJAX — hromadné smazání fotek (bulk_delete)
   ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'bulk_delete') {
    header('Content-Type: application/json; charset=utf-8');

    $ids = array_filter(array_map('intval', (array)($_POST['photo_ids'] ?? [])));
    if (empty($ids)) {
        echo json_encode(['ok' => false, 'msg' => 'Žádná ID.']);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = $pdo->prepare("SELECT id, filename FROM track_photos WHERE id IN ($placeholders)");
    $rows->execute($ids);
    $toDelete = $rows->fetchAll(PDO::FETCH_ASSOC);

    foreach ($toDelete as $row) {
        @unlink(uploads_fs('photos/' . $row['filename']));
        @unlink(uploads_fs('photos/thumbs/' . $row['filename']));
    }
    $pdo->prepare("DELETE FROM track_photos WHERE id IN ($placeholders)")->execute($ids);

    echo json_encode(['ok' => true, 'deleted' => count($toDelete)]);
    exit;
}

/* =====================================================================
   AJAX — hromadné přiřazení fotek k trase (bulk_assign)
   ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'bulk_assign') {
    header('Content-Type: application/json; charset=utf-8');

    $ids     = array_filter(array_map('intval', (array)($_POST['photo_ids'] ?? [])));
    $trackId = $_POST['track_id'] !== '' ? (int)$_POST['track_id'] : null;

    if (empty($ids)) {
        echo json_encode(['ok' => false, 'msg' => 'Žádná ID.']);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$trackId], $ids);
    $pdo->prepare("UPDATE track_photos SET track_id = ? WHERE id IN ($placeholders)")->execute($params);

    echo json_encode(['ok' => true, 'updated' => count($ids)]);
    exit;
}

/* =====================================================================
   AJAX — přepnutí viditelnosti fotky na trase
   ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'toggle_visible') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$_isAdmin) { echo json_encode(['ok' => false]); exit; }

    $photoId = (int)($_POST['photo_id'] ?? 0);
    if (!$photoId) { echo json_encode(['ok' => false, 'msg' => 'Chybí photo_id']); exit; }

    $row = $pdo->prepare("SELECT visible FROM track_photos WHERE id = ?");
    $row->execute([$photoId]);
    $current = $row->fetchColumn();
    $newVal  = ($current == 0) ? 1 : 0;

    $pdo->prepare("UPDATE track_photos SET visible = ? WHERE id = ?")->execute([$newVal, $photoId]);
    echo json_encode(['ok' => true, 'visible' => $newVal]);
    exit;
}

/* =====================================================================
   AJAX — hromadné přepnutí viditelnosti fotek (bulk_visible)
   ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'bulk_visible') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$_isAdmin) { echo json_encode(['ok' => false]); exit; }

    $ids     = array_filter(array_map('intval', (array)($_POST['photo_ids'] ?? [])));
    $visible = isset($_POST['visible']) ? (int)(bool)$_POST['visible'] : null;

    if (empty($ids) || $visible === null) {
        echo json_encode(['ok' => false, 'msg' => 'Chybí parametry']); exit;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$visible], $ids);
    $pdo->prepare("UPDATE track_photos SET visible = ? WHERE id IN ($placeholders)")->execute($params);
    echo json_encode(['ok' => true, 'visible' => $visible, 'ids' => array_values($ids)]);
    exit;
}

/* =====================================================================
   Data pro HTML stránku
   ===================================================================== */

// Globální statistiky (lehký dotaz)
$visibleOnly = $_isAdmin ? '' : 'WHERE (visible IS NULL OR visible = 1)';
$statsRow   = $pdo->query("
    SELECT COUNT(*) AS total,
           SUM(lat IS NOT NULL) AS with_gps,
           SUM(track_id IS NOT NULL) AS with_track
    FROM track_photos
    $visibleOnly
")->fetch(PDO::FETCH_ASSOC);
$totalCount = (int)$statsRow['total'];
$withGps    = (int)$statsRow['with_gps'];
$withTrack  = (int)$statsRow['with_track'];
$unassigned = $totalCount - $withTrack;

// Stránkování galerie po skupinách tras
$GALLERY_PER_PAGE_OPTIONS = [50, 100, 250, 500];
$galleryPerPage = (int)($_GET['per_page'] ?? 250);
if (!in_array($galleryPerPage, $GALLERY_PER_PAGE_OPTIONS)) $galleryPerPage = 250;
$galleryPage = max(1, (int)($_GET['page'] ?? 1));

// Skupiny s počty fotek (seřazeno stejně jako fotky)
$visibleWhere = $_isAdmin ? '' : 'WHERE (p.visible IS NULL OR p.visible = 1)';
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
$allGroups = $groupsStmt->fetchAll(PDO::FETCH_ASSOC);

// Rozdělit skupiny do stránek podle součtu fotek
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
$totalGalleryPages  = max(1, count($paginatedPages));
$galleryPage        = min($galleryPage, $totalGalleryPages);
$currentPageGroups  = $paginatedPages[$galleryPage - 1] ?? [];

// Načíst fotky jen pro aktuální stránku
$pageTrackIds  = array_map(fn($g) => $g['track_id'], $currentPageGroups);
$hasNullGroup  = in_array(null, $pageTrackIds, true);
$nonNullIds    = array_values(array_filter($pageTrackIds, fn($id) => $id !== null));

$pagePhotos = [];
if (!empty($nonNullIds) || $hasNullGroup) {
    $parts  = [];
    $params = [];
    if (!empty($nonNullIds)) {
        $parts[]  = 'p.track_id IN (' . implode(',', array_fill(0, count($nonNullIds), '?')) . ')';
        $params   = $nonNullIds;
    }
    if ($hasNullGroup) $parts[] = 'p.track_id IS NULL';

    $visibleCond = $_isAdmin ? '' : 'AND (p.visible IS NULL OR p.visible = 1)';
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
    $pagePhotos = $photosStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Pro záložku Nepřiřazené (jen nepřiřazené — malý dotaz)
$unassignedPhotos = [];
if ($unassigned > 0) {
    $unassignedPhotos = $pdo->query("
        SELECT p.id, p.filename, p.orig_name, p.lat, p.lon, p.taken_at, p.track_id
        FROM track_photos p
        WHERE p.track_id IS NULL
        ORDER BY p.taken_at DESC, p.id DESC
        LIMIT 500
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Seznam tras pro přiřazovací select
$tracksForSelect = $pdo->query("
    SELECT id, track_name, filename, date_start
    FROM   tracks
    ORDER  BY date_start DESC
    LIMIT  500
")->fetchAll(PDO::FETCH_ASSOC);

// ===== Časová osa (Timeline) =====
$timelineVisible = $_isAdmin ? '' : 'AND (p.visible IS NULL OR p.visible = 1)';
$timelinePhotos = $pdo->query("
    SELECT p.*, t.track_name, t.filename AS track_filename
    FROM track_photos p
    LEFT JOIN tracks t ON t.id = p.track_id
    WHERE p.taken_at IS NOT NULL $timelineVisible
    ORDER BY p.taken_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

$timeline = [];
foreach ($timelinePhotos as $tp) {
    $month = substr($tp['taken_at'], 0, 7);  // "2024-04"
    $day   = substr($tp['taken_at'], 0, 10); // "2024-04-15"
    $timeline[$month][$day][] = $tp;
}

// Přeložit měsíce do češtiny
$monthNamesCz = [
    '01' => 'Leden', '02' => 'Únor', '03' => 'Březen', '04' => 'Duben',
    '05' => 'Květen', '06' => 'Červen', '07' => 'Červenec', '08' => 'Srpen',
    '09' => 'Září', '10' => 'Říjen', '11' => 'Listopad', '12' => 'Prosinec',
];
$currentYear = date('Y');
?>

<?php
$page_title = 'Fotografie tras';
require __DIR__ . '/includes/layout_header.php';
?>

<!-- Photos page styling — outdoor tokenizovaný (přebíjí potřebné proměnné) -->
<link rel="stylesheet" href="css/photos-outdoor.css">
<!-- Lightbox z původního systému -->
<script src="js/lightbox.js" defer></script>

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <a href="index.php" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
        <i data-lucide="arrow-left" class="w-4 h-4"></i>
        Zpět na seznam
    </a>
    <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
        <i data-lucide="image" class="w-8 h-8 text-terracotta-500"></i>
        Fotografie tras
    </h1>
    <p class="mt-1 text-forest-700/70 dark:text-sand-100/70 text-sm">
        Galerie, časová osa a správa fotografií z výletů
    </p>
</section>

<!-- Statistiky -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-6">
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="card-outdoor p-4">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                <i data-lucide="image" class="w-3.5 h-3.5"></i> Celkem
            </div>
            <div class="mt-1.5 stat-num text-2xl font-semibold text-forest-700 dark:text-sand-100"><?= number_format($totalCount, 0, ',', ' ') ?></div>
            <div class="text-xs text-forest-700/60 dark:text-sand-100/60 mt-0.5">fotek</div>
        </div>
        <div class="card-outdoor p-4">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                <i data-lucide="map-pin" class="w-3.5 h-3.5"></i> S GPS
            </div>
            <div class="mt-1.5 stat-num text-2xl font-semibold text-terracotta-500"><?= number_format($withGps, 0, ',', ' ') ?></div>
            <div class="text-xs text-forest-700/60 dark:text-sand-100/60 mt-0.5">polohou</div>
        </div>
        <div class="card-outdoor p-4">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                <i data-lucide="route" class="w-3.5 h-3.5"></i> Přiřazeno
            </div>
            <div class="mt-1.5 stat-num text-2xl font-semibold text-forest-600"><?= number_format($withTrack, 0, ',', ' ') ?></div>
            <div class="text-xs text-forest-700/60 dark:text-sand-100/60 mt-0.5">k trase</div>
        </div>
        <?php if ($unassigned > 0): ?>
        <div class="card-outdoor p-4 border-l-4 border-l-terracotta-500">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-terracotta-500">
                <i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i> Pozor
            </div>
            <div class="mt-1.5 stat-num text-2xl font-semibold text-terracotta-500"><?= number_format($unassigned, 0, ',', ' ') ?></div>
            <div class="text-xs text-forest-700/60 dark:text-sand-100/60 mt-0.5">nepřiřazených</div>
        </div>
        <?php else: ?>
        <div class="card-outdoor p-4">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                <i data-lucide="check-circle" class="w-3.5 h-3.5"></i> Pořízeno
            </div>
            <div class="mt-1.5 stat-num text-2xl font-semibold text-forest-700 dark:text-sand-100">100<span class="text-sm">%</span></div>
            <div class="text-xs text-forest-700/60 dark:text-sand-100/60 mt-0.5">přiřazeno</div>
        </div>
        <?php endif; ?>
    </div>
</section>

<div class="mx-auto max-w-7xl px-4 sm:px-6 mt-8">

<!-- Záložky -->
<?php
$activeTab = $_GET['tab'] ?? ($_isAdmin ? 'upload' : 'gallery');
if (!in_array($activeTab, ['upload','gallery','unassigned','timeline'])) $activeTab = $_isAdmin ? 'upload' : 'gallery';
if (!$_isAdmin && $activeTab === 'upload') $activeTab = 'gallery';
?>
<div class="photos-tabs">
    <?php if ($_isAdmin): ?>
    <button class="photos-tab <?= $activeTab === 'upload'     ? 'active' : '' ?>" data-tab="upload">⬆ Nahrát fotky</button>
    <button class="photos-tab <?= $activeTab === 'gallery'    ? 'active' : '' ?>" data-tab="gallery">🖼 Správa (<?= $totalCount ?>)</button>
    <?php if ($unassigned > 0): ?>
    <button class="photos-tab <?= $activeTab === 'unassigned' ? 'active' : '' ?>" data-tab="unassigned">⚠ Nepřiřazené (<?= $unassigned ?>)</button>
    <?php endif; ?>
    <?php else: ?>
    <button class="photos-tab <?= $activeTab === 'gallery'    ? 'active' : '' ?>" data-tab="gallery">🖼 Fotografie (<?= $totalCount ?>)</button>
    <?php endif; ?>
    <?php if (!empty($timelinePhotos)): ?>
    <button class="photos-tab <?= $activeTab === 'timeline'   ? 'active' : '' ?>" data-tab="timeline">📅 Časová osa (<?= count($timelinePhotos) ?>)</button>
    <?php endif; ?>
</div>

<!-- Záložka: Upload (pouze admin) -->
<div class="photos-tab-content <?= $activeTab === 'upload' ? 'active' : '' ?>" id="tab-upload" <?= !$_isAdmin ? 'style="display:none!important;"' : '' ?>>

    <div class="photo-dropzone" id="photoDropzone">
        <div class="drop-icon">📷</div>
        <div>Přetáhněte fotky sem nebo <strong>klikněte pro výběr</strong></div>
        <div class="drop-formats">JPEG · PNG · WebP · ZIP (Google Takeout) · Max. 20 souborů najednou</div>
        <input type="file" id="photoFileInput" accept="image/jpeg,image/png,image/webp,.zip,application/zip" multiple>
    </div>

    <div id="uploadProgress"></div>

</div>

<!-- Záložka: Správa / Galerie -->
<div class="photos-tab-content <?= $activeTab === 'gallery' ? 'active' : '' ?>" id="tab-gallery">
<?php if ($totalCount === 0): ?>
    <p style="color:var(--text-muted); font-size:14px;">Zatím žádné fotky. Nahrajte je v záložce ⬆ Nahrát fotky.</p>
<?php else: ?>
    <?php
    // Stránkovací lišta
    $pgUrl = fn(int $p) => '?' . http_build_query(array_merge($_GET, ['page' => $p, 'per_page' => $galleryPerPage, 'tab' => 'gallery']));
    ?>
    <div class="gallery-pager">
        <?php if ($galleryPage > 1): ?>
            <a class="btn" href="<?= h($pgUrl(1)) ?>">« První</a>
            <a class="btn" href="<?= h($pgUrl($galleryPage - 1)) ?>">← Předchozí</a>
        <?php else: ?>
            <span class="btn" style="opacity:.4; cursor:default;">« První</span>
            <span class="btn" style="opacity:.4; cursor:default;">← Předchozí</span>
        <?php endif; ?>
        <span class="pager-info">Strana <strong><?= $galleryPage ?></strong> / <?= $totalGalleryPages ?></span>
        <?php if ($galleryPage < $totalGalleryPages): ?>
            <a class="btn" href="<?= h($pgUrl($galleryPage + 1)) ?>">Další →</a>
            <a class="btn" href="<?= h($pgUrl($totalGalleryPages)) ?>">Poslední »</a>
        <?php else: ?>
            <span class="btn" style="opacity:.4; cursor:default;">Další →</span>
            <span class="btn" style="opacity:.4; cursor:default;">Poslední »</span>
        <?php endif; ?>
        <form method="get" style="display:inline-flex; align-items:center; gap:4px; margin:0;">
            <?php foreach (array_merge($_GET, ['tab' => 'gallery', 'per_page' => $galleryPerPage]) as $k => $v): ?>
                <?php if ($k !== 'page'): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php endif; ?>
            <?php endforeach; ?>
            <input type="number" name="page" min="1" max="<?= $totalGalleryPages ?>"
                   value="<?= $galleryPage ?>"
                   style="width:54px; font-size:12px; padding:3px 6px; text-align:center;"
                   title="Skočit na stranu">
            <button type="submit" class="btn" style="font-size:12px; padding:3px 8px;">Jít</button>
        </form>
        <select class="select" style="font-size:12px; padding:4px 8px;"
                onchange="location.href='<?= h('?' . http_build_query(array_merge($_GET, ['page' => 1, 'per_page' => '__PP__', 'tab' => 'gallery']))) ?>'.replace('__PP__', this.value)">
            <?php foreach ($GALLERY_PER_PAGE_OPTIONS as $pp): ?>
            <option value="<?= $pp ?>" <?= $pp === $galleryPerPage ? 'selected' : '' ?>><?= $pp ?> fotek / strana</option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php
    // Seskupit fotky aktuální stránky dle trasy
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
    ?>
    <?php foreach ($groupedForDisplay as $grp): ?>
    <div class="track-section" id="track-section-<?= (int)($grp['track_id'] ?? 0) ?>">
        <div class="track-section-header">
            <?php if ($grp['track_id']): ?>
                🗺 <a href="detail.php?id=<?= (int)$grp['track_id'] ?>"><?= h($grp['track_name'] ?? '—') ?></a>
                <span class="track-date"><?= $grp['date_start'] ? substr($grp['date_start'], 0, 10) : '' ?></span>
                <span style="margin-left:auto; font-size:12px; color:var(--text-muted);"><?= count($grp['photos']) ?> fotek</span>
            <?php else: ?>
                ⚠ Nepřiřazené fotky
                <span style="margin-left:auto; font-size:12px; color:var(--text-muted);"><?= count($grp['photos']) ?> fotek</span>
            <?php endif; ?>
            <?php if ($_isAdmin): ?>
                <button class="btn btn-track-sel-all" style="font-size:11px; padding:2px 8px; margin-left:8px;"
                        data-section="track-section-<?= (int)($grp['track_id'] ?? 0) ?>">☑ Vybrat</button>
                <button class="btn btn-track-sel-none" style="font-size:11px; padding:2px 8px;"
                        data-section="track-section-<?= (int)($grp['track_id'] ?? 0) ?>">✕ Zrušit</button>
            <?php endif; ?>
        </div>
        <div class="track-section-body">
            <div class="photo-grid">
                <?php foreach ($grp['photos'] as $p): ?>
                <div class="photo-card<?= empty($p['visible']) ? ' photo-is-hidden' : '' ?>" data-id="<?= (int)$p['id'] ?>">
                    <?php if ($_isAdmin): ?>
                    <div class="photo-select-wrap">
                        <input type="checkbox" class="photo-select-cb" data-id="<?= (int)$p['id'] ?>">
                    </div>
                    <?php endif; ?>
                    <?php if (!$p['lat']): ?>
                        <div class="photo-no-gps">Bez GPS</div>
                    <?php endif; ?>
                    <img src="<?= h(photo_thumb_url($p['filename'])) ?>"
                         data-full-url="<?= h(photo_full_url($p['filename'])) ?>"
                         data-taken-at="<?= h($p['taken_at'] ?? '') ?>"
                         alt="<?= h($p['orig_name'] ?? $p['filename']) ?>"
                         loading="lazy"
                         onerror="this.style.opacity='.3'">
                    <div class="photo-meta">
                        <?php if ($p['taken_at']): ?>
                            📅 <?= substr($p['taken_at'], 0, 16) ?>
                        <?php endif; ?>
                        <?php if ($p['lat']): ?>
                            <br>📍 <?= round((float)$p['lat'], 4) ?>, <?= round((float)$p['lon'], 4) ?>
                        <?php endif; ?>
                        <?php if (!empty($p['img_direction'])): ?>
                            <br>🧭 <?= round((float)$p['img_direction']) ?>°
                        <?php endif; ?>
                    </div>
                    <?php if ($_isAdmin): ?>
                    <div class="photo-caption-wrap" data-id="<?= (int)$p['id'] ?>">
                        <div class="photo-caption-display" title="Kliknutím upravit popisek">
                            <?= $p['caption'] ? h($p['caption']) : '<span class="caption-empty">+ popisek</span>' ?>
                        </div>
                        <div class="photo-caption-edit" style="display:none;">
                            <input type="text" class="caption-input" maxlength="200"
                                   placeholder="Krátký popisek fotky…"
                                   value="<?= h($p['caption'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="photo-actions">
                        <button class="btn btn-visible<?= empty($p['visible']) ? ' btn-hidden' : '' ?>"
                                data-id="<?= (int)$p['id'] ?>"
                                data-visible="<?= empty($p['visible']) ? '0' : '1' ?>"
                                title="<?= empty($p['visible']) ? 'Skrytá — kliknutím zobrazit na trase' : 'Zobrazená na trase — kliknutím skrýt' ?>"
                        ><?= empty($p['visible']) ? '🙈' : '👁' ?></button>
                        <button class="btn btn-assign"
                                data-id="<?= (int)$p['id'] ?>"
                                data-track="<?= (int)($p['track_id'] ?? 0) ?>"
                                title="Přiřadit k trase">✏</button>
                        <button class="btn btn-delete"
                                data-id="<?= (int)$p['id'] ?>"
                                data-name="<?= h($p['orig_name'] ?? $p['filename']) ?>"
                                title="Smazat fotku">🗑</button>
                    </div>
                    <?php elseif ($p['caption']): ?>
                    <div class="photo-caption-wrap">
                        <div class="photo-caption-display" style="cursor:default;">
                            <?= h($p['caption']) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Spodní stránkovací lišta -->
    <?php if ($totalGalleryPages > 1): ?>
    <div class="gallery-pager" style="margin-top:12px;">
        <?php if ($galleryPage > 1): ?>
            <a class="btn" href="<?= h($pgUrl(1)) ?>">« První</a>
            <a class="btn" href="<?= h($pgUrl($galleryPage - 1)) ?>">← Předchozí</a>
        <?php else: ?>
            <span class="btn" style="opacity:.4; cursor:default;">« První</span>
            <span class="btn" style="opacity:.4; cursor:default;">← Předchozí</span>
        <?php endif; ?>
        <span class="pager-info">Strana <strong><?= $galleryPage ?></strong> / <?= $totalGalleryPages ?></span>
        <?php if ($galleryPage < $totalGalleryPages): ?>
            <a class="btn" href="<?= h($pgUrl($galleryPage + 1)) ?>">Další →</a>
            <a class="btn" href="<?= h($pgUrl($totalGalleryPages)) ?>">Poslední »</a>
        <?php else: ?>
            <span class="btn" style="opacity:.4; cursor:default;">Další →</span>
            <span class="btn" style="opacity:.4; cursor:default;">Poslední »</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>
</div>

<!-- Záložka: Nepřiřazené (jen pokud existují) -->
<?php if ($unassigned > 0): ?>
<div class="photos-tab-content" id="tab-unassigned">
    <p style="font-size:13px; color:var(--text-muted); margin-bottom:12px;">
        Tyto fotky nebylo možné automaticky přiřadit k trase (chybí GPS, čas nesedí nebo žádná trasa neexistuje v daném okně).
        Přiřadit je můžete ručně tlačítkem ✏.
        <?php if ($unassigned > 500): ?><strong>Zobrazeno prvních 500.</strong><?php endif; ?>
    </p>
    <div class="photo-grid">
        <?php foreach ($unassignedPhotos as $p): ?>
        <div class="photo-card" data-id="<?= (int)$p['id'] ?>">
            <div class="photo-select-wrap">
                <input type="checkbox" class="photo-select-cb" data-id="<?= (int)$p['id'] ?>">
            </div>
            <?php if (!$p['lat']): ?>
                <div class="photo-no-gps">Bez GPS</div>
            <?php endif; ?>
            <img src="<?= h(photo_thumb_url($p['filename'])) ?>"
                 data-full-url="<?= h(photo_full_url($p['filename'])) ?>"
                 data-taken-at="<?= h($p['taken_at'] ?? '') ?>"
                 alt="<?= h($p['orig_name'] ?? $p['filename']) ?>"
                 loading="lazy"
                 onerror="this.style.opacity='.3'">
            <div class="photo-meta">
                <?= h($p['orig_name'] ?? $p['filename']) ?><br>
                <?php if ($p['taken_at']): ?>📅 <?= substr($p['taken_at'], 0, 16) ?><?php endif; ?>
            </div>
            <div class="photo-actions">
                <button class="btn btn-assign"
                        data-id="<?= (int)$p['id'] ?>"
                        data-track="0"
                        title="Přiřadit k trase">✏ Přiřadit</button>
                <button class="btn btn-delete"
                        data-id="<?= (int)$p['id'] ?>"
                        data-name="<?= h($p['orig_name'] ?? $p['filename']) ?>">🗑</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Záložka: Časová osa -->
<?php if (!empty($timelinePhotos)): ?>
<div class="photos-tab-content" id="tab-timeline">
    <?php if (empty($timeline)): ?>
        <p style="color:var(--text-muted); font-size:14px;">Žádné fotky s datem pořízení.</p>
    <?php else: ?>
        <?php foreach ($timeline as $month => $days): ?>
            <?php
            $monthNum   = substr($month, 5, 2);
            $year       = substr($month, 0, 4);
            $monthName  = ($monthNamesCz[$monthNum] ?? $month) . ' ' . $year;
            $monthCount = array_sum(array_map('count', $days));
            $isOpenDefault = ($year === $currentYear);
            ?>
            <details class="timeline-month" <?= $isOpenDefault ? 'open' : '' ?>>
                <summary>
                    📅 <?= h($monthName) ?>
                    <span class="timeline-month-count"><?= $monthCount ?> fotek</span>
                </summary>
                <div class="timeline-days">
                    <?php foreach ($days as $day => $dayPhotos): ?>
                        <?php
                        $dayTs    = strtotime($day);
                        $dayLabel = date('j. n. Y', $dayTs);
                        ?>
                        <div class="timeline-day">
                            <div class="timeline-day-label">📆 <?= h($dayLabel) ?></div>
                            <div class="timeline-thumbs" data-timeline-day="<?= h($day) ?>">
                                <?php foreach ($dayPhotos as $tp): ?>
                                <img class="timeline-thumb lazy-thumb"
                                     src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs="
                                     data-src="<?= h(photo_thumb_url($tp['filename'])) ?>"
                                     data-full-url="<?= h(photo_full_url($tp['filename'])) ?>"
                                     data-taken-at="<?= h($tp['taken_at'] ?? '') ?>"
                                     alt="<?= h($tp['orig_name'] ?? $tp['filename']) ?>"
                                     onerror="this.src='';this.style.opacity='.3'"
                                     title="<?= h(substr($tp['taken_at'] ?? '', 0, 16)) . ($tp['track_name'] ? ' · ' . h($tp['track_name']) : '') ?>">
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Bulk action bar (sticky bottom, pouze admin) -->
<div class="bulk-bar" id="bulkActionBar" <?= !$_isAdmin ? 'style="display:none!important;"' : '' ?>>
    <span class="bulk-count" id="bulkCount">0 vybráno</span>
    <button class="btn" id="bulkAssignBtn">✏ Přiřadit k trase</button>
    <button class="btn btn-bulk-delete" id="bulkDeleteBtn">🗑 Smazat vybrané</button>
    <span class="bulk-sep"></span>
    <button class="btn" id="bulkSelectAllBtn">☑ Vybrat vše</button>
    <button class="btn" id="bulkClearBtn">✕ Zrušit výběr</button>
</div>

<!-- Modal: přiřazení k trase -->
<div class="modal-overlay" id="assignModal">
    <div class="modal-box">
        <h3>✏ Přiřadit fotku k trase</h3>
        <select id="assignTrackSelect" class="select">
            <option value="">— Nepřiřazená —</option>
            <?php foreach ($tracksForSelect as $tr): ?>
            <option value="<?= (int)$tr['id'] ?>">
                <?= h($tr['track_name'] ?: $tr['filename']) ?>
                <?= $tr['date_start'] ? ' (' . substr($tr['date_start'], 0, 10) . ')' : '' ?>
            </option>
            <?php endforeach; ?>
        </select>
        <div class="modal-actions">
            <button class="btn" id="assignCancelBtn">Zrušit</button>
            <button class="btn btn-primary" id="assignSaveBtn">Uložit</button>
        </div>
    </div>
</div>

<script>
/* ===================== Záložky ===================== */
document.querySelectorAll('.photos-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.photos-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.photos-tab-content').forEach(c => c.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('tab-' + tab.dataset.tab)?.classList.add('active');
        // Clear bulk selection when switching tabs
        clearBulkSelection();
    });
});

/* ===================== Aktivní záložka z URL hash ===================== */
(function () {
    const hash = location.hash.replace('#', '');
    if (!hash) return;
    const tab = document.querySelector(`.photos-tab[data-tab="${hash}"]`);
    if (!tab) return;
    document.querySelectorAll('.photos-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.photos-tab-content').forEach(c => c.classList.remove('active'));
    tab.classList.add('active');
    document.getElementById('tab-' + hash)?.classList.add('active');
})();

/* ===================== Přímý odkaz na trasu (?track_id=X) ===================== */
(function () {
    const params  = new URLSearchParams(location.search);
    const trackId = params.get('track_id');
    if (!trackId) return;

    // Přepnout na záložku Správa
    document.querySelectorAll('.photos-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.photos-tab-content').forEach(c => c.classList.remove('active'));
    const galleryTab = document.querySelector('.photos-tab[data-tab="gallery"]');
    if (galleryTab) galleryTab.classList.add('active');
    document.getElementById('tab-gallery')?.classList.add('active');

    // Scroll na sekci trasy a zvýraznit ji
    const section = document.getElementById('track-section-' + trackId);
    if (section) {
        setTimeout(() => {
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            section.style.outline = '2px solid var(--accent-color)';
            section.style.borderRadius = '8px';
            setTimeout(() => { section.style.outline = ''; }, 2500);
        }, 100);
    }
})();

/* ===================== Upload ===================== */
const dropzone  = document.getElementById('photoDropzone');
const fileInput = document.getElementById('photoFileInput');
const progress  = document.getElementById('uploadProgress');

dropzone.addEventListener('click', () => fileInput.click());
dropzone.addEventListener('dragover', e => { e.preventDefault(); dropzone.classList.add('dragover'); });
dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
dropzone.addEventListener('drop', e => {
    e.preventDefault();
    dropzone.classList.remove('dragover');
    if (e.dataTransfer.files.length) uploadFiles(e.dataTransfer.files);
});
fileInput.addEventListener('change', () => {
    if (fileInput.files.length) uploadFiles(fileInput.files);
});

async function uploadFiles(files) {
    const hasZip = Array.from(files).some(f => f.name.toLowerCase().endsWith('.zip'));
    const uploadMsg = hasZip
        ? `⏳ Nahrávám ZIP (může trvat déle)…`
        : `⏳ Nahrávám ${files.length} ${files.length === 1 ? 'fotku' : 'fotek'}…`;
    progress.innerHTML = `<div style="color:var(--text-muted); font-size:13px;">${uploadMsg}</div>`;

    const fd = new FormData();
    for (const f of files) fd.append('photos[]', f);

    try {
        const resp = await fetch('photos.php?ajax=upload', { method: 'POST', body: fd });
        const data = await resp.json();

        progress.innerHTML = '';
        for (const r of data.results) {
            const div = document.createElement('div');
            div.className = 'upload-result-row';
            if (r.ok) {
                const sizeInfo = (r.orig_size && r.stored_size)
                    ? ` <span style="color:var(--text-muted); font-size:11px;">${fmtSize(r.orig_size)} → ${fmtSize(r.stored_size)}</span>`
                    : '';
                div.innerHTML = `
                    <img src="${r.thumb_url}" onerror="this.style.opacity='.3'">
                    <div class="res-info">
                        <span class="badge-ok">✓</span> ${escHtml(r.file)}${sizeInfo}
                        <br><span class="badge-gps">${r.has_gps ? '📍 GPS nalezena' : '⚠ Bez GPS — nezobrazí se na mapě'}</span>
                        ${r.track_name ? `<div class="res-track">🗺 Přiřazeno: ${escHtml(r.track_name)}</div>` : '<div class="res-track">⚠ Nepřiřazeno k trase</div>'}
                    </div>`;
            } else if (r.duplicate) {
                div.innerHTML = `
                    <img src="${r.thumb_url}" onerror="this.style.opacity='.3'">
                    <div class="res-info">
                        <span style="color:#e65c00; font-weight:700;">⚠ Duplikát</span> ${escHtml(r.file)}
                        <br><span class="badge-gps" style="color:#e65c00;">${escHtml(r.msg)}</span>
                        ${r.track_name ? `<div class="res-track">🗺 ${escHtml(r.track_name)}</div>` : ''}
                    </div>`;
            } else {
                div.innerHTML = `<div class="res-info"><span class="badge-err">✗</span> ${escHtml(r.file)}: ${escHtml(r.msg)}</div>`;
            }
            progress.appendChild(div);
        }

        // Reload stránky po 2s pro aktualizaci galerie
        const okCount = data.results.filter(r => r.ok).length;
        if (okCount > 0) {
            const note = document.createElement('div');
            note.style.cssText = 'margin-top:10px; font-size:13px; color:var(--text-muted);';
            note.textContent = `✅ Nahráno ${okCount} fotek. Stránka se za chvíli obnoví…`;
            progress.appendChild(note);
            setTimeout(() => location.reload(), 2500);
        }
    } catch (err) {
        progress.innerHTML = `<div style="color:#c62828;">Chyba: ${err.message}</div>`;
    }
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fmtSize(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    if (bytes >= 1024)    return Math.round(bytes / 1024) + ' KB';
    return bytes + ' B';
}

/* ===================== Přiřazení k trase ===================== */
let currentAssignPhotoId = null;
const assignModal  = document.getElementById('assignModal');
const assignSelect = document.getElementById('assignTrackSelect');

document.querySelectorAll('.btn-assign').forEach(btn => {
    btn.addEventListener('click', () => {
        currentAssignPhotoId = parseInt(btn.dataset.id);
        assignSelect.value = btn.dataset.track || '';
        assignModal.classList.add('open');
    });
});

document.getElementById('assignCancelBtn').addEventListener('click', () => {
    assignModal.classList.remove('open');
});

// assignSaveBtn handler is defined below (handles both single + bulk)

/* ===================== Viditelnost fotky ===================== */

// Helpers — aktualizuje UI tlačítka + karty bez AJAX
function applyVisibleUI(btn, visible) {
    const card = btn.closest('.photo-card');
    btn.dataset.visible = visible ? '1' : '0';
    btn.textContent     = visible ? '👁' : '🙈';
    btn.title           = visible ? 'Zobrazená na trase — kliknutím skrýt' : 'Skrytá — kliknutím zobrazit na trase';
    btn.classList.toggle('btn-hidden', !visible);
    if (card) card.classList.toggle('photo-is-hidden', !visible);
}

let _lastVisibleBtn   = null;  // kotva pro shift+click
let _lastVisibleState = null;  // stav nastavený posledním (ne-shift) klikem

document.querySelectorAll('.btn-visible').forEach(btn => {
    btn.addEventListener('click', async (e) => {
        const allBtns = Array.from(document.querySelectorAll('.btn-visible'));

        if (e.shiftKey && _lastVisibleBtn && _lastVisibleBtn !== btn && _lastVisibleState !== null) {
            // ── Range operace ──
            const idxA = allBtns.indexOf(_lastVisibleBtn);
            const idxB = allBtns.indexOf(btn);
            if (idxA === -1 || idxB === -1) return;
            const [from, to] = idxA < idxB ? [idxA, idxB] : [idxB, idxA];
            const rangeState = _lastVisibleState;  // aplikujeme stav posledního kotevního kliknutí
            const rangeBtns  = allBtns.slice(from, to + 1);
            const rangeIds   = rangeBtns.map(b => b.dataset.id);

            // Okamžitě aktualizuj UI
            rangeBtns.forEach(b => applyVisibleUI(b, rangeState));

            // Jeden hromadný AJAX
            const fd = new FormData();
            fd.append('ajax', 'bulk_visible');
            fd.append('visible', rangeState ? '1' : '0');
            rangeIds.forEach(id => fd.append('photo_ids[]', id));
            const resp = await fetch('photos.php', { method: 'POST', body: fd });
            const data = await resp.json();
            if (!data.ok) {
                // Rollback UI při chybě
                rangeBtns.forEach(b => applyVisibleUI(b, !rangeState));
            }
            return;
        }

        // ── Normální single klik ──
        const fd = new FormData();
        fd.append('ajax', 'toggle_visible');
        fd.append('photo_id', btn.dataset.id);
        const resp = await fetch('photos.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (!data.ok) return;
        const visible = data.visible === 1;
        applyVisibleUI(btn, visible);
        _lastVisibleBtn   = btn;
        _lastVisibleState = visible;
    });
});

/* ===================== Smazání ===================== */
document.querySelectorAll('.btn-delete').forEach(btn => {
    btn.addEventListener('click', async () => {
        const name = btn.dataset.name;
        if (!confirm(`Smazat fotku "${name}"? Tuto akci nelze vrátit.`)) return;
        const fd = new FormData();
        fd.append('ajax', 'delete');
        fd.append('photo_id', btn.dataset.id);
        const resp = await fetch('photos.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.ok) btn.closest('.photo-card').remove();
    });
});

/* ===================== Caption inline edit (B2) ===================== */
document.addEventListener('click', e => {
    const display = e.target.closest('.photo-caption-display');
    if (!display) return;
    const wrap  = display.closest('.photo-caption-wrap');
    const edit  = wrap.querySelector('.photo-caption-edit');
    const input = wrap.querySelector('.caption-input');
    display.style.display = 'none';
    edit.style.display    = 'block';
    input.focus();
    input.select();
});

document.addEventListener('keydown', async e => {
    const input = e.target.closest('.caption-input');
    if (!input) return;
    if (e.key === 'Escape') {
        cancelCaptionEdit(input);
        return;
    }
    if (e.key === 'Enter') {
        await saveCaptionEdit(input);
    }
});

document.addEventListener('focusout', async e => {
    const input = e.target.closest('.caption-input');
    if (!input) return;
    // Small delay so click events fire first
    setTimeout(() => saveCaptionEdit(input), 120);
});

async function saveCaptionEdit(input) {
    const wrap    = input.closest('.photo-caption-wrap');
    if (!wrap || wrap.dataset.saving) return;
    wrap.dataset.saving = '1';
    const photoId = parseInt(wrap.dataset.id);
    const caption = input.value.trim();

    const fd = new FormData();
    fd.append('ajax', 'update_caption');
    fd.append('photo_id', photoId);
    fd.append('caption', caption);

    try {
        await fetch('photos.php', { method: 'POST', body: fd });
    } catch (_) {}

    const display = wrap.querySelector('.photo-caption-display');
    const edit    = wrap.querySelector('.photo-caption-edit');
    display.innerHTML = caption
        ? escHtml(caption)
        : '<span class="caption-empty">+ popisek</span>';
    display.style.display = '';
    edit.style.display    = 'none';
    delete wrap.dataset.saving;
}

function cancelCaptionEdit(input) {
    const wrap    = input.closest('.photo-caption-wrap');
    const display = wrap.querySelector('.photo-caption-display');
    const edit    = wrap.querySelector('.photo-caption-edit');
    display.style.display = '';
    edit.style.display    = 'none';
    delete wrap.dataset.saving;
}

/* ===================== Lightbox — galerie ===================== */
function initGridLightbox(grid) {
    const imgs = Array.from(grid.querySelectorAll('img[data-full-url]'));
    imgs.forEach((img, i) => {
        img.style.cursor = 'pointer';
        img.addEventListener('click', () => {
            const photos = imgs.map(el => ({
                full_url: el.dataset.fullUrl,
                alt:      el.alt,
                taken_at: el.dataset.takenAt || ''
            }));
            window.gpxLightbox?.open(photos, i);
        });
    });
}

// Init for all existing grids
document.querySelectorAll('.photo-grid').forEach(initGridLightbox);

/* ===================== Throttled lazy loader ===================== */
(function () {
    const MAX_CONCURRENT = 4;
    let loading = 0;
    const queue = [];

    function loadNext() {
        while (loading < MAX_CONCURRENT && queue.length) {
            const img = queue.shift();
            if (!img.dataset.src) continue;
            loading++;
            const src = img.dataset.src;
            const tmp = new Image();
            tmp.onload = tmp.onerror = () => {
                if (tmp.naturalWidth) img.src = src;
                else img.style.opacity = '.3';
                delete img.dataset.src;
                loading--;
                loadNext();
            };
            tmp.src = src;
        }
    }

    function enqueue(img) {
        if (!img.dataset.src || img._lazyQueued) return;
        img._lazyQueued = true;
        queue.push(img);
        loadNext();
    }

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(e => { if (e.isIntersecting) enqueue(e.target); });
    }, { rootMargin: '200px' });

    function observeAll() {
        document.querySelectorAll('img.lazy-thumb[data-src]').forEach(img => {
            observer.observe(img);
        });
    }

    // observe on load and whenever a <details> is opened (new thumbnails become relevant)
    observeAll();
    document.addEventListener('toggle', e => {
        if (e.target.classList?.contains('timeline-month')) observeAll();
    }, true);
})();

/* ===================== Lightbox — timeline ===================== */
document.querySelectorAll('[data-timeline-day]').forEach(dayEl => {
    const imgs = Array.from(dayEl.querySelectorAll('img[data-full-url]'));
    imgs.forEach((img, i) => {
        img.addEventListener('click', () => {
            const photos = imgs.map(el => ({
                full_url: el.dataset.fullUrl,
                alt:      el.alt,
                taken_at: el.dataset.takenAt || ''
            }));
            window.gpxLightbox?.open(photos, i);
        });
    });
});

/* ===================== Hromadné operace (Bulk) ===================== */
const bulkBar       = document.getElementById('bulkActionBar');
const bulkCountEl   = document.getElementById('bulkCount');
const bulkAssignBtn = document.getElementById('bulkAssignBtn');
const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
const bulkSelAllBtn = document.getElementById('bulkSelectAllBtn');
const bulkClearBtn  = document.getElementById('bulkClearBtn');

function getSelectedIds() {
    return Array.from(document.querySelectorAll('.photo-select-cb:checked'))
                .map(cb => parseInt(cb.dataset.id));
}

function updateBulkBar() {
    const ids = getSelectedIds();
    bulkCountEl.textContent = ids.length + ' vybráno';
    if (ids.length > 0) {
        bulkBar.classList.add('visible');
    } else {
        bulkBar.classList.remove('visible');
    }
    // Mark/unmark cards
    document.querySelectorAll('.photo-card').forEach(card => {
        const cb = card.querySelector('.photo-select-cb');
        if (cb) card.classList.toggle('selected', cb.checked);
    });
}

function clearBulkSelection() {
    document.querySelectorAll('.photo-select-cb').forEach(cb => cb.checked = false);
    updateBulkBar();
}

/* ── Shift+click range selection na checkboxech ── */
let _lastCb = null;  // kotva pro shift+click

document.addEventListener('click', e => {
    const cb = e.target;
    if (!cb.classList || !cb.classList.contains('photo-select-cb')) return;

    if (e.shiftKey && _lastCb && _lastCb !== cb) {
        const allCbs = Array.from(document.querySelectorAll('.photo-select-cb'));
        const idxA   = allCbs.indexOf(_lastCb);
        const idxB   = allCbs.indexOf(cb);
        if (idxA !== -1 && idxB !== -1) {
            const [from, to] = idxA < idxB ? [idxA, idxB] : [idxB, idxA];
            const target = cb.checked;  // stav po kliknutí (prohlížeč ho už přepnul)
            for (let i = from; i <= to; i++) allCbs[i].checked = target;
        }
    }

    _lastCb = cb;
    updateBulkBar();
});

document.addEventListener('change', e => {
    if (e.target.classList.contains('photo-select-cb')) updateBulkBar();
});

// Select all visible in active tab
if (bulkSelAllBtn) {
    bulkSelAllBtn.addEventListener('click', () => {
        const activeContent = document.querySelector('.photos-tab-content.active');
        if (!activeContent) return;
        activeContent.querySelectorAll('.photo-select-cb').forEach(cb => cb.checked = true);
        updateBulkBar();
    });
}

if (bulkClearBtn) {
    bulkClearBtn.addEventListener('click', clearBulkSelection);
}

// Select/clear per track section
document.addEventListener('click', e => {
    const selAll  = e.target.closest('.btn-track-sel-all');
    const selNone = e.target.closest('.btn-track-sel-none');
    if (!selAll && !selNone) return;
    const sectionId = (selAll || selNone).dataset.section;
    const section   = document.getElementById(sectionId);
    if (!section) return;
    section.querySelectorAll('.photo-select-cb').forEach(cb => {
        cb.checked = !!selAll;
    });
    updateBulkBar();
});

// Bulk delete
if (bulkDeleteBtn) {
    bulkDeleteBtn.addEventListener('click', async () => {
        const ids = getSelectedIds();
        if (!ids.length) return;
        if (!confirm(`Smazat ${ids.length} vybraných fotek? Tuto akci nelze vrátit.`)) return;

        const fd = new FormData();
        fd.append('ajax', 'bulk_delete');
        ids.forEach(id => fd.append('photo_ids[]', id));

        try {
            const resp = await fetch('photos.php', { method: 'POST', body: fd });
            const data = await resp.json();
            if (data.ok) {
                ids.forEach(id => {
                    document.querySelectorAll(`.photo-card[data-id="${id}"]`).forEach(c => c.remove());
                });
                clearBulkSelection();
            }
        } catch (err) {
            alert('Chyba při mazání: ' + err.message);
        }
    });
}

// Bulk assign — opens modal, assigns after save
let _bulkAssigning = false;
if (bulkAssignBtn) {
    bulkAssignBtn.addEventListener('click', () => {
        const ids = getSelectedIds();
        if (!ids.length) return;
        _bulkAssigning = true;
        currentAssignPhotoId = null; // signal that this is bulk
        assignSelect.value = '';
        assignModal.classList.add('open');
    });
}

// Override assignSaveBtn to handle bulk
document.getElementById('assignSaveBtn').addEventListener('click', async () => {
    if (_bulkAssigning) {
        _bulkAssigning = false;
        const ids = getSelectedIds();
        if (!ids.length) { assignModal.classList.remove('open'); return; }

        const fd = new FormData();
        fd.append('ajax', 'bulk_assign');
        fd.append('track_id', assignSelect.value);
        ids.forEach(id => fd.append('photo_ids[]', id));

        const resp = await fetch('photos.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.ok) {
            assignModal.classList.remove('open');
            clearBulkSelection();
            location.reload();
        }
        return;
    }
    // Single assign (original logic)
    if (!currentAssignPhotoId) return;
    const fd = new FormData();
    fd.append('ajax', 'assign');
    fd.append('photo_id', currentAssignPhotoId);
    fd.append('track_id', assignSelect.value);
    const resp = await fetch('photos.php', { method: 'POST', body: fd });
    const data = await resp.json();
    if (data.ok) { assignModal.classList.remove('open'); location.reload(); }
});
</script>

</div><!-- /content wrapper -->

<?php require __DIR__ . '/includes/layout_footer.php'; ?>

