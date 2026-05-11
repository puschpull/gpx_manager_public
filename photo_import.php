<?php
/**
 * GPX Manager — Lokální import fotek
 * Skenuje lokální adresář, zobrazí galerii s EXIF preview
 * a umožní hromadný import vybraných fotek do projektu.
 * Přístupné pouze pro adminy.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/photo_helper.php';

/* ===== Theme ===== */
$allThemes = ['classic','dark','darkblue','darkgreen','blue','green','minimal','lightgray','brown'];
$available_themes = get_app_config('allowed_themes', $allThemes);
$theme = $_GET['theme'] ?? ($_COOKIE['theme'] ?? 'classic');
if (!in_array($theme, $available_themes)) $theme = $available_themes[0] ?? 'classic';
if (isset($_GET['theme'])) setcookie('theme', $theme, time() + 365 * 24 * 3600, '/');

/* =====================================================================
   Pomocná funkce — ověření bezpečnosti lokální cesty
   ===================================================================== */
function is_safe_image_path(string $path): bool {
    $real = realpath($path);
    if ($real === false) return false;
    $ext  = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg','jpeg','png','webp'], true);
}

/* =====================================================================
   AJAX — skenování adresáře
   ===================================================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'scan') {
    header('Content-Type: application/json; charset=utf-8');

    $dir = trim($_GET['dir'] ?? '');
    if ($dir === '' || !is_dir($dir)) {
        echo json_encode(['ok' => false, 'msg' => 'Adresář neexistuje nebo nelze číst.']);
        exit;
    }

    $files = [];
    $limit = 50000;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) continue;
            $files[] = [
                'path'  => $file->getRealPath(),
                'name'  => $file->getFilename(),
                'size'  => $file->getSize(),
                'mtime' => $file->getMTime(),
            ];
            if (count($files) >= $limit) break;
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => 'Chyba při skenování: ' . $e->getMessage()]);
        exit;
    }

    // Seřadit podle mtime (nejnovější první)
    usort($files, fn($a, $b) => $b['mtime'] - $a['mtime']);

    echo json_encode(['ok' => true, 'files' => $files, 'total' => count($files)]);
    exit;
}

/* =====================================================================
   AJAX — EXIF batch (+ dedup check + auto-assign preview)
   ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'exif_batch') {
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    ini_set('display_errors', 0);
    set_time_limit(120);
    ini_set('memory_limit', '256M');

    $paths = $_POST['paths'] ?? [];
    if (!is_array($paths) || empty($paths)) {
        echo json_encode(['ok' => false, 'msg' => 'Chybí paths']);
        exit;
    }

    $results = [];
    foreach ($paths as $path) {
        $path = (string)$path;
        if (!is_safe_image_path($path) || !is_readable($path)) {
            $results[] = ['path' => $path, 'error' => true];
            continue;
        }

        // EXIF (čtení hlavičky — rychlé, nečte celý soubor)
        $exif = read_photo_exif($path);

        // Rychlá dedup kontrola podle orig_name (bez sha1_file — to se dělá až při importu)
        $origName = basename($path);
        $dupCheck = $pdo->prepare("SELECT id FROM track_photos WHERE orig_name = ? LIMIT 1");
        $dupCheck->execute([$origName]);
        $isDup = (bool)$dupCheck->fetch();

        // Auto-assign preview (bez DB insertu)
        $trackId   = null;
        $trackName = null;
        if ($exif['lat'] !== null && $exif['lon'] !== null && $exif['taken_at'] !== null) {
            $trackId = auto_assign_photo_to_track($exif['lat'], $exif['lon'], $exif['taken_at']);
            if ($trackId) {
                $r = $pdo->prepare("SELECT track_name, filename FROM tracks WHERE id = ?");
                $r->execute([$trackId]);
                $row = $r->fetch(PDO::FETCH_ASSOC);
                $trackName = $row ? ($row['track_name'] ?: $row['filename']) : null;
            }
        }

        $results[] = [
            'path'       => $path,
            'is_dup'     => $isDup,
            'has_gps'    => ($exif['lat'] !== null && $exif['lon'] !== null),
            'lat'        => $exif['lat'],
            'lon'        => $exif['lon'],
            'taken_at'   => $exif['taken_at'],
            'track_id'   => $trackId,
            'track_name' => $trackName,
        ];
    }

    ob_end_clean();
    echo json_encode(['ok' => true, 'results' => $results]);
    exit;
}

/* =====================================================================
   AJAX — inline thumbnail z lokálního souboru
   ===================================================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'thumb') {
    $encoded = $_GET['p'] ?? '';
    $path    = base64_decode($encoded, true);

    if ($path === false || !is_safe_image_path($path) || !is_readable($path)) {
        http_response_code(404);
        exit;
    }

    // ETag cache (na základě mtime + size)
    $mtime = filemtime($path);
    $size  = filesize($path);
    $etag  = '"' . md5($path . $mtime . $size) . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: private, max-age=3600');
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
        http_response_code(304);
        exit;
    }

    // Nejdřív zkusit embedded EXIF thumbnail — rychlé, nečte celý soubor
    if (extension_loaded('exif')) {
        $thumbData = @exif_thumbnail($path);
        if ($thumbData !== false && strlen($thumbData) > 100) {
            header('Content-Type: image/jpeg');
            header('Content-Length: ' . strlen($thumbData));
            echo $thumbData;
            exit;
        }
    }

    // Fallback: GD resize do temp souboru (jen pokud EXIF thumbnail chybí)
    $tmp = sys_get_temp_dir() . '/gpx_imp_' . md5($path . $mtime) . '.jpg';
    if (!file_exists($tmp)) {
        generate_photo_thumb($path, $tmp, 120);
    }

    if (!file_exists($tmp)) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    exit;
}

/* =====================================================================
   AJAX — import vybraných fotek
   ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'do_import') {
    header('Content-Type: application/json; charset=utf-8');
    set_time_limit(0);
    ini_set('memory_limit', '512M');

    $paths = $_POST['paths'] ?? [];
    if (!is_array($paths) || empty($paths)) {
        echo json_encode(['ok' => false, 'msg' => 'Chybí paths']);
        exit;
    }

    $results = [];
    foreach ($paths as $path) {
        $path = (string)$path;
        if (!is_safe_image_path($path) || !is_readable($path)) {
            $results[] = ['ok' => false, 'file' => basename($path), 'msg' => 'Soubor nelze číst'];
            continue;
        }
        $result = process_single_photo($path, basename($path), (int)filesize($path));
        $result['file'] = basename($path);
        $results[] = $result;
    }

    echo json_encode(['ok' => true, 'results' => $results]);
    exit;
}

/* =====================================================================
   HTML stránka
   ===================================================================== */
render_admin_banner();
?>
<!DOCTYPE html>
<html lang="cs" data-theme="<?= h($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lokální import fotek — GPX Manager</title>

    <script>
    (function() {
        const match = document.cookie.match(/theme=([^;]+)/);
        const theme = match ? match[1] : 'classic';
        const link  = document.createElement('link');
        link.rel    = 'stylesheet';
        link.id     = 'theme-style';
        link.href   = 'css/theme-' + theme + '.css';
        document.head.appendChild(link);
        document.documentElement.setAttribute('data-theme', theme);
    })();
    </script>

    <link rel="stylesheet" href="css/style.css">

    <style>
        .imp-wrap {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px 16px 80px;
        }
        .imp-title {
            font-size: 22px;
            margin: 0 0 6px;
        }
        .imp-subtitle {
            font-size: 13px;
            color: var(--text-muted);
            margin: 0 0 20px;
        }

        /* Path input */
        .imp-path-row {
            display: flex;
            gap: 8px;
            margin-bottom: 8px;
        }
        .imp-path-row input[type=text] {
            flex: 1;
            padding: 10px 14px;
            font-size: 14px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--card-bg);
            color: var(--text-color);
            font-family: monospace;
        }
        .imp-path-row input[type=text]:focus {
            outline: none;
            border-color: var(--accent-color);
        }
        .btn-scan {
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            background: var(--accent-color);
            color: #fff;
            white-space: nowrap;
        }
        .btn-scan:disabled { opacity: .5; cursor: default; }

        /* Záložky posledních cest */
        .imp-recent {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 18px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }
        .imp-recent-chip {
            background: var(--bg-secondary, var(--card-bg));
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 2px 8px;
            color: var(--text-muted);
            cursor: pointer;
            text-decoration: none;
            font-family: monospace;
            font-size: 12px;
        }
        .imp-recent-chip:hover { color: var(--accent-color); border-color: var(--accent-color); }

        /* Toolbar */
        .imp-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            margin-bottom: 12px;
            padding: 10px 14px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
        }
        .imp-stats {
            font-size: 13px;
            color: var(--text-muted);
            flex: 1;
            min-width: 200px;
        }
        .imp-stats strong { color: var(--text-color); }
        .imp-filter-btn {
            padding: 5px 12px;
            font-size: 12px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            background: var(--card-bg);
            color: var(--text-color);
            cursor: pointer;
            white-space: nowrap;
        }
        .imp-filter-btn.active {
            background: var(--accent-color);
            color: #fff;
            border-color: var(--accent-color);
        }
        .imp-filter-btn:hover:not(.active) {
            border-color: var(--accent-color);
            color: var(--accent-color);
        }

        /* Galerie */
        .imp-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
            margin-bottom: 20px;
        }
        .imp-card {
            position: relative;
            background: var(--card-bg);
            border: 2px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
            cursor: pointer;
            transition: border-color .15s, box-shadow .15s;
            user-select: none;
        }
        .imp-card:hover { border-color: var(--accent-color); }
        .imp-card.selected {
            border-color: var(--accent-color);
            box-shadow: 0 0 0 2px var(--accent-color);
        }
        .imp-card.is-dup {
            opacity: .45;
            cursor: default;
        }
        .imp-card.hidden { display: none; }

        .imp-card-img-wrap {
            position: relative;
            width: 100%;
            aspect-ratio: 1;
            overflow: hidden;
            background: var(--bg-secondary, #e8e8e8);
        }
        .imp-card-thumb {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .imp-card-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: var(--text-muted);
        }

        .imp-card-check {
            position: absolute;
            top: 5px;
            left: 5px;
            width: 22px;
            height: 22px;
            background: rgba(0,0,0,.45);
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            z-index: 2;
        }
        .imp-card.selected .imp-card-check { background: var(--accent-color); }

        .imp-card-badge {
            position: absolute;
            top: 5px;
            right: 5px;
            background: #888;
            color: #fff;
            font-size: 9px;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 3px;
            z-index: 2;
            display: none;
        }

        .imp-card-info {
            padding: 5px 7px 6px;
            font-size: 11px;
            line-height: 1.4;
        }
        .imp-card-name {
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: var(--text-color);
            margin-bottom: 2px;
        }
        .imp-card-meta {
            color: var(--text-muted);
            font-size: 10px;
        }
        .imp-card-track {
            color: var(--accent-color);
            font-size: 10px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-top: 1px;
        }
        .gps-yes { color: #2e7d32; }
        .gps-no  { color: #aaa; }

        /* Sticky import bar */
        .imp-action-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--card-bg);
            border-top: 2px solid var(--border-color);
            padding: 10px 20px;
            display: flex;
            gap: 12px;
            align-items: center;
            z-index: 200;
            box-shadow: 0 -2px 10px rgba(0,0,0,.1);
        }
        .imp-action-bar .imp-sel-info {
            font-size: 14px;
            font-weight: 600;
            flex: 1;
            color: var(--text-color);
        }
        .imp-action-bar .imp-sel-info small {
            font-weight: 400;
            color: var(--text-muted);
            margin-left: 8px;
        }
        .btn-import {
            padding: 9px 24px;
            font-size: 14px;
            font-weight: 700;
            border: none;
            border-radius: 6px;
            background: #2e7d32;
            color: #fff;
            cursor: pointer;
            white-space: nowrap;
        }
        .btn-import:disabled { opacity: .4; cursor: default; }
        .btn-import:not(:disabled):hover { background: #1b5e20; }

        /* Progress */
        .imp-progress-wrap {
            margin-bottom: 16px;
            display: none;
        }
        .imp-progress {
            height: 8px;
            background: var(--border-color);
            border-radius: 4px;
            overflow: hidden;
        }
        .imp-progress-bar {
            height: 100%;
            background: var(--accent-color);
            width: 0;
            transition: width .25s;
            border-radius: 4px;
        }
        .imp-progress-text {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 5px;
        }

        /* Výsledky */
        .imp-result-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 7px 0;
            border-bottom: 1px solid var(--border-color);
            font-size: 13px;
        }
        .imp-result-row img {
            width: 44px;
            height: 44px;
            object-fit: cover;
            border-radius: 4px;
            flex-shrink: 0;
        }
        .imp-result-icon {
            width: 44px;
            height: 44px;
            border-radius: 4px;
            background: var(--bg-secondary, #eee);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .imp-result-info { flex: 1; min-width: 0; }
        .imp-result-sub { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
        .res-ok  { color: #2e7d32; font-weight: 700; }
        .res-dup { color: #888;    font-weight: 700; }
        .res-err { color: #c62828; font-weight: 700; }

        /* Loading */
        .imp-loading {
            text-align: center;
            padding: 50px 20px;
            color: var(--text-muted);
        }
        .imp-spinner {
            display: inline-block;
            width: 32px;
            height: 32px;
            border: 3px solid var(--border-color);
            border-top-color: var(--accent-color);
            border-radius: 50%;
            animation: imp-spin .8s linear infinite;
            margin-bottom: 12px;
        }
        @keyframes imp-spin { to { transform: rotate(360deg); } }

        .imp-empty-msg {
            text-align: center;
            padding: 50px;
            color: var(--text-muted);
            font-size: 15px;
        }
    </style>
</head>
<body>
<div class="imp-wrap">

<h1 class="imp-title">📥 Lokální import fotek</h1>
<p class="imp-subtitle">Skenuje lokální adresář na počítači, zobrazí náhled s EXIF daty a umožní hromadný import přímo do projektu.</p>

<!-- Vstup cesty -->
<div class="imp-path-row">
    <input type="text" id="dirInput" placeholder="Cesta k adresáři, např. D:\Fotky\2024\Alpy\" autocomplete="off" spellcheck="false">
    <button class="btn-scan" id="btnScan">🔍 Skenovat</button>
</div>
<div class="imp-recent" id="recentDirs"></div>

<!-- Načítání -->
<div id="impLoading" style="display:none;">
    <div class="imp-loading">
        <div class="imp-spinner"></div>
        <div>Skenuji adresář…</div>
    </div>
</div>

<!-- Hlavní sekce galerie -->
<div id="gallerySection" style="display:none;">

    <!-- Toolbar -->
    <div class="imp-toolbar">
        <div class="imp-stats" id="impStats">—</div>
        <button class="imp-filter-btn active" data-filter="all">Vše</button>
        <button class="imp-filter-btn" data-filter="new">Jen nové</button>
        <button class="imp-filter-btn" data-filter="gps">Jen GPS</button>
        <button class="imp-filter-btn" data-filter="gps_new">GPS + nové</button>
        <button class="imp-filter-btn" data-filter="track">Jen s trasou</button>
        <button class="imp-filter-btn" id="btnSelectAll">☑ Vybrat viditelné</button>
        <button class="imp-filter-btn" id="btnDeselectAll">☐ Zrušit výběr</button>
    </div>

    <!-- Výsledky importu -->
    <div id="impResults"></div>

    <!-- Galerie karet -->
    <div class="imp-gallery" id="impGallery"></div>

</div>

<!-- Sticky action bar -->
<div class="imp-action-bar" style="flex-wrap:wrap; gap:8px;">
    <div class="imp-sel-info">
        <span id="selCount">Vybráno: 0</span>
        <small id="selHint">— nejdřív naskenuj adresář</small>
    </div>
    <div id="barProgress" style="display:none;flex:1;min-width:200px;">
        <div class="imp-progress"><div class="imp-progress-bar" id="impProgressBar"></div></div>
        <div class="imp-progress-text" id="impProgressText"></div>
    </div>
    <a href="photos.php" style="font-size:13px;color:var(--text-muted);text-decoration:none;">← Galerie fotek</a>
    <a href="index.php" style="font-size:13px;color:var(--text-muted);text-decoration:none;">← Přehled tras</a>
    <button class="btn-import" id="btnImport" disabled>Importovat vybrané</button>
</div>

</div><!-- .imp-wrap -->

<script>
(function () {
'use strict';

/* ── Konstanty ─────────────────────────────────────── */
const EXIF_BATCH  = 25;   // fotek na jeden exif_batch request
const IMP_BATCH   = 8;    // fotek na jeden do_import request
const RECENT_KEY  = 'gpx_photo_import_dirs';
const RECENT_MAX  = 6;

/* ── State ─────────────────────────────────────────── */
let allFiles     = [];
let selected     = new Set();
let activeFilter = 'all';

/* ── DOM refs ──────────────────────────────────────── */
const dirInput       = document.getElementById('dirInput');
const btnScan        = document.getElementById('btnScan');
const recentEl       = document.getElementById('recentDirs');
const galleryEl      = document.getElementById('impGallery');
const gallerySection = document.getElementById('gallerySection');
const loadingEl      = document.getElementById('impLoading');
const impStats       = document.getElementById('impStats');
const selCountEl     = document.getElementById('selCount');
const selHintEl      = document.getElementById('selHint');
const btnImport      = document.getElementById('btnImport');
const impResults     = document.getElementById('impResults');
const progressWrap   = document.getElementById('barProgress');
const progressBar    = document.getElementById('impProgressBar');
const progressText   = document.getElementById('impProgressText');

/* ── Poslední cesty ────────────────────────────────── */
function loadRecent() {
    try { return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); }
    catch(e) { return []; }
}
function saveRecent(dir) {
    let arr = loadRecent().filter(d => d !== dir);
    arr.unshift(dir);
    localStorage.setItem(RECENT_KEY, JSON.stringify(arr.slice(0, RECENT_MAX)));
    renderRecent();
}
function renderRecent() {
    const dirs = loadRecent();
    if (!dirs.length) { recentEl.innerHTML = ''; return; }
    recentEl.innerHTML = '<span style="color:var(--text-muted)">Naposledy:</span> ' +
        dirs.map(d =>
            `<a class="imp-recent-chip" href="#" onclick="event.preventDefault();useDir(${JSON.stringify(d)})">${escHtml(d)}</a>`
        ).join('');
}
window.useDir = function(d) { dirInput.value = d; startScan(); };
renderRecent();

/* ── Skenování ─────────────────────────────────────── */
btnScan.addEventListener('click', startScan);
dirInput.addEventListener('keydown', e => { if (e.key === 'Enter') startScan(); });

async function startScan() {
    const dir = dirInput.value.trim();
    if (!dir) { dirInput.focus(); return; }

    allFiles = [];
    selected.clear();
    gallerySection.style.display = 'none';
    impResults.innerHTML = '';
    loadingEl.style.display = '';
    btnScan.disabled = true;
    progressWrap.style.display = 'none';
    progressBar.style.width = '0%';
    selHintEl.textContent = '— skenuju…';

    try {
        const resp = await fetch('photo_import.php?ajax=scan&dir=' + encodeURIComponent(dir));
        const data = await resp.json();

        if (!data.ok) {
            loadingEl.innerHTML =
                `<div class="imp-loading" style="color:#c62828;">⚠ ${escHtml(data.msg)}</div>`;
            btnScan.disabled = false;
            return;
        }

        if (!data.files.length) {
            loadingEl.innerHTML =
                `<div class="imp-loading">Žádné fotky (JPEG/PNG/WebP) nenalezeny v adresáři.</div>`;
            btnScan.disabled = false;
            return;
        }

        allFiles = data.files.map(f => ({
            path:       f.path,
            name:       f.name,
            size:       f.size,
            mtime:      f.mtime,
            exifLoaded: false,
            hasGps:     null,
            isDup:      null,
            takenAt:    null,
            trackName:  null,
        }));

        saveRecent(dir);
        loadingEl.style.display = 'none';
        gallerySection.style.display = '';
        renderGallery();
        updateStats();
        btnScan.disabled = false;
        selHintEl.textContent = '— načítám EXIF…';

        // Asynchronně načíst EXIF po dávkách
        loadExifBatches();

    } catch(err) {
        loadingEl.innerHTML =
            `<div class="imp-loading" style="color:#c62828;">Chyba: ${escHtml(String(err))}</div>`;
        btnScan.disabled = false;
    }
}

/* ── Galerie ───────────────────────────────────────── */
function renderGallery() {
    galleryEl.innerHTML = '';
    allFiles.forEach((f, idx) => galleryEl.appendChild(createCard(f, idx)));
    applyFilter();
}

function thumbUrl(path) {
    const bytes = new TextEncoder().encode(path);
    let bin = '';
    bytes.forEach(b => bin += String.fromCharCode(b));
    return 'photo_import.php?ajax=thumb&p=' + encodeURIComponent(btoa(bin));
}

// Fronta thumbnailů — max 4 souběžné requesty (ochrana Apache)
const THUMB_CONCURRENCY = 4;
let thumbActive = 0;
const thumbQueue = [];

function thumbNext() {
    while (thumbActive < THUMB_CONCURRENCY && thumbQueue.length > 0) {
        const img = thumbQueue.shift();
        if (!img.dataset.src) continue;  // už načteno nebo odstraněno
        thumbActive++;
        const src = img.dataset.src;
        delete img.dataset.src;
        const loader = new Image();
        loader.onload  = () => { img.src = src; thumbActive--; thumbNext(); };
        loader.onerror = () => { img.dispatchEvent(new Event('error')); thumbActive--; thumbNext(); };
        loader.src = src;
    }
}

// IntersectionObserver přidává do fronty, nenačítá přímo
const thumbObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        const img = entry.target;
        if (img.dataset.src) thumbQueue.push(img);
        thumbObserver.unobserve(img);
    });
    thumbNext();
}, { rootMargin: '300px' });

function observeThumb(img) {
    if (img.dataset.src) thumbObserver.observe(img);
}

function createCard(f, idx) {
    const card = document.createElement('div');
    card.className = 'imp-card';
    card.dataset.idx = String(idx);

    card.innerHTML = `
        <div class="imp-card-img-wrap">
            <div class="imp-card-check">☐</div>
            <div class="imp-card-badge">DUP</div>
            <img class="imp-card-thumb" data-src="${thumbUrl(f.path)}" alt="">
        </div>
        <div class="imp-card-info">
            <div class="imp-card-name" title="${escHtml(f.name)}">${escHtml(f.name)}</div>
            <div class="imp-card-meta imp-card-meta-${idx}">
                ${fmtDate(f.mtime * 1000)} &nbsp; ${fmtSize(f.size)}
            </div>
            <div class="imp-card-meta imp-card-gps-${idx}" style="margin-top:1px;"></div>
            <div class="imp-card-track imp-card-track-${idx}"></div>
        </div>`;

    // Lazy load + fallback pokud thumbnail selže
    const img = card.querySelector('.imp-card-thumb');
    img.addEventListener('error', () => {
        img.style.display = 'none';
        const wrap = card.querySelector('.imp-card-img-wrap');
        const ph = document.createElement('div');
        ph.className = 'imp-card-placeholder';
        ph.textContent = '🖼';
        wrap.appendChild(ph);
    });
    observeThumb(img);

    card.addEventListener('click', () => toggleSelect(idx));
    return card;
}

function toggleSelect(idx) {
    const f = allFiles[idx];
    if (f.isDup) return;
    if (selected.has(idx)) selected.delete(idx);
    else selected.add(idx);
    refreshCardUI(idx);
    updateStats();
}

function refreshCardUI(idx) {
    const card = galleryEl.querySelector(`[data-idx="${idx}"]`);
    if (!card) return;
    const f   = allFiles[idx];
    const sel = selected.has(idx);

    card.classList.toggle('selected', sel);
    card.classList.toggle('is-dup', !!f.isDup);

    const checkEl = card.querySelector('.imp-card-check');
    if (checkEl) checkEl.textContent = sel ? '✓' : (f.isDup ? '✗' : '☐');

    const badgeEl = card.querySelector('.imp-card-badge');
    if (badgeEl) badgeEl.style.display = f.isDup ? '' : 'none';

    if (f.exifLoaded) {
        const gpsEl = card.querySelector('.imp-card-gps-' + idx);
        if (gpsEl) {
            gpsEl.innerHTML = f.hasGps
                ? '<span class="gps-yes">🟢 GPS</span>'
                : '<span class="gps-no">⚪ bez GPS</span>';
        }
        if (f.takenAt) {
            const metaEl = card.querySelector('.imp-card-meta-' + idx);
            if (metaEl) metaEl.textContent = f.takenAt.slice(0, 16).replace('T', ' ') + ' · ' + fmtSize(f.size);
        }
        const trackEl = card.querySelector('.imp-card-track-' + idx);
        if (trackEl) trackEl.textContent = f.trackName ? '→ ' + f.trackName : '';
    }
}

function applyFilter() {
    allFiles.forEach((f, idx) => {
        const card = galleryEl.querySelector(`[data-idx="${idx}"]`);
        if (!card) return;
        let show = true;
        switch (activeFilter) {
            case 'new':     show = !f.isDup; break;
            case 'gps':     show = f.hasGps === true; break;
            case 'gps_new': show = f.hasGps === true && !f.isDup; break;
            case 'track':   show = !!f.trackName; break;
        }
        card.classList.toggle('hidden', !show);
    });
}

/* ── EXIF batch loading ────────────────────────────── */
async function loadExifBatches() {
    const total = allFiles.length;
    for (let i = 0; i < total; i += EXIF_BATCH) {
        const slice = allFiles.slice(i, i + EXIF_BATCH);
        const paths = slice.map(f => f.path);

        try {
            const form = new FormData();
            form.append('ajax', 'exif_batch');
            paths.forEach(p => form.append('paths[]', p));

            const resp = await fetch('photo_import.php', { method: 'POST', body: form });
            const data = await resp.json();

            if (data.ok && Array.isArray(data.results)) {
                data.results.forEach(r => {
                    const idx = allFiles.findIndex(f => f.path === r.path);
                    if (idx < 0) return;
                    allFiles[idx].exifLoaded = true;
                    allFiles[idx].hasGps     = r.has_gps;
                    allFiles[idx].isDup      = r.is_dup;
                    allFiles[idx].takenAt    = r.taken_at;
                    allFiles[idx].trackName  = r.track_name;
                    if (r.is_dup) selected.delete(idx);
                    refreshCardUI(idx);
                });
                updateStats();
                applyFilter();
            }
        } catch(err) {
            console.warn('exif_batch chyba:', err);
        }
    }
    selHintEl.textContent = '';
}

/* ── Statistiky ────────────────────────────────────── */
function updateStats() {
    const total   = allFiles.length;
    const loaded  = allFiles.filter(f => f.exifLoaded).length;
    const withGps = allFiles.filter(f => f.hasGps).length;
    const fresh   = allFiles.filter(f => f.exifLoaded && !f.isDup).length;
    const dups    = allFiles.filter(f => f.isDup).length;

    impStats.innerHTML =
        `Nalezeno: <strong>${total}</strong> &nbsp;·&nbsp; ` +
        `S GPS: <strong>${withGps}</strong> &nbsp;·&nbsp; ` +
        `Nových: <strong>${fresh}</strong> &nbsp;·&nbsp; ` +
        `Duplikátů: <strong>${dups}</strong>` +
        (loaded < total ? ` &nbsp;·&nbsp; <em>EXIF: ${loaded}/${total}</em>` : '');

    const n = selected.size;
    selCountEl.textContent = `Vybráno: ${n}`;
    btnImport.disabled = n === 0;
    btnImport.textContent = n > 0 ? `Importovat vybrané (${n})` : 'Importovat vybrané';
}

/* ── Filtry ────────────────────────────────────────── */
document.querySelectorAll('.imp-filter-btn[data-filter]').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.imp-filter-btn[data-filter]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        activeFilter = btn.dataset.filter;
        applyFilter();
    });
});

document.getElementById('btnSelectAll').addEventListener('click', () => {
    allFiles.forEach((f, idx) => {
        const card = galleryEl.querySelector(`[data-idx="${idx}"]`);
        if (card && !card.classList.contains('hidden') && !f.isDup) {
            selected.add(idx);
            refreshCardUI(idx);
        }
    });
    updateStats();
});

document.getElementById('btnDeselectAll').addEventListener('click', () => {
    selected.clear();
    allFiles.forEach((_, idx) => refreshCardUI(idx));
    updateStats();
});

/* ── Import ────────────────────────────────────────── */
btnImport.addEventListener('click', startImport);

async function startImport() {
    const indices = [...selected];
    if (!indices.length) return;

    btnImport.disabled = true;
    btnScan.disabled   = true;
    impResults.innerHTML = '';
    progressWrap.style.display = '';
    progressBar.style.width    = '0%';
    progressBar.style.background = 'var(--accent-color)';

    const paths = indices.map(i => allFiles[i].path);
    const total = paths.length;
    let done = 0, okCount = 0, dupCount = 0, errCount = 0;

    // Vložit výsledky na začátek sekce
    const resultHeader = document.createElement('h3');
    resultHeader.style.cssText = 'font-size:15px;margin:0 0 8px;';
    resultHeader.textContent = 'Výsledky importu';
    impResults.appendChild(resultHeader);

    for (let i = 0; i < total; i += IMP_BATCH) {
        const batchPaths = paths.slice(i, i + IMP_BATCH);
        try {
            const form = new FormData();
            form.append('ajax', 'do_import');
            batchPaths.forEach(p => form.append('paths[]', p));

            const resp = await fetch('photo_import.php', { method: 'POST', body: form });
            const data = await resp.json();

            if (data.ok && Array.isArray(data.results)) {
                data.results.forEach(r => {
                    done++;
                    if (r.ok) okCount++;
                    else if (r.duplicate) dupCount++;
                    else errCount++;
                    impResults.appendChild(buildResultRow(r));
                });
            }
        } catch(err) {
            done += batchPaths.length;
            errCount += batchPaths.length;
            console.error('do_import chyba:', err);
        }

        const pct = Math.round((done / total) * 100);
        progressBar.style.width = pct + '%';
        progressText.textContent = `${done} / ${total} zpracováno…`;
    }

    progressText.textContent =
        `Hotovo: ✅ importováno ${okCount}` +
        (dupCount ? ` · ⏭ duplikátů ${dupCount}` : '') +
        (errCount ? ` · ❌ chyb ${errCount}` : '');
    progressBar.style.background = errCount > 0 ? '#e53935' : '#2e7d32';

    if (okCount > 0) {
        const link = document.createElement('p');
        link.style.cssText = 'margin:10px 0 16px;';
        link.innerHTML = `<a href="photos.php" style="color:var(--accent-color);font-weight:600;font-size:14px;">→ Zobrazit ${okCount} nových fotek v galerii</a>`;
        impResults.insertBefore(link, resultHeader.nextSibling);
    }

    // Reset výběru a znovu načíst EXIF (označí importované jako dup)
    selected.clear();
    allFiles.forEach(f => { f.exifLoaded = false; f.isDup = null; f.hasGps = null; });
    allFiles.forEach((_, idx) => refreshCardUI(idx));
    updateStats();
    loadExifBatches();

    btnScan.disabled = false;
    // btnImport zůstane disabled dokud uživatel nevybere znovu
}

function buildResultRow(r) {
    const row = document.createElement('div');
    row.className = 'imp-result-row';

    let badge = '', sub = '';
    if (r.ok) {
        badge = '<span class="res-ok">✅ OK</span>';
        sub   = r.track_name ? `Přiřazeno: ${escHtml(r.track_name)}` : '(bez přiřazené trasy)';
        if (r.orig_size && r.stored_size && r.orig_size > r.stored_size) {
            const pct = Math.round((1 - r.stored_size / r.orig_size) * 100);
            sub += ` · zmenšeno o ${pct}%`;
        }
    } else if (r.duplicate) {
        badge = '<span class="res-dup">⏭ Duplikát</span>';
        sub   = r.track_name ? `Již přiřazeno: ${escHtml(r.track_name)}` : 'Již v databázi';
    } else {
        badge = '<span class="res-err">❌ Chyba</span>';
        sub   = escHtml(r.msg || '');
    }

    const imgPart = r.thumb_url
        ? `<img src="${escHtml(r.thumb_url)}" alt="">`
        : `<div class="imp-result-icon">🖼</div>`;

    row.innerHTML = `
        ${imgPart}
        <div class="imp-result-info">
            <div>${badge} <strong>${escHtml(r.file || '')}</strong></div>
            <div class="imp-result-sub">${sub}</div>
        </div>`;
    return row;
}

/* ── Utility ───────────────────────────────────────── */
function escHtml(s) {
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtDate(ms) {
    const d = new Date(ms);
    return d.getFullYear() + '-'
        + String(d.getMonth()+1).padStart(2,'0') + '-'
        + String(d.getDate()).padStart(2,'0');
}
function fmtSize(b) {
    if (b < 1024) return b + ' B';
    if (b < 1024*1024) return (b/1024).toFixed(0) + ' KB';
    return (b/1024/1024).toFixed(1) + ' MB';
}

})();
</script>

</body>
</html>
