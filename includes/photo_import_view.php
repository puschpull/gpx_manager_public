<?php
/**
 * photo_import_view.php — HTML template for the local photo import page.
 *
 * Expected variables (set by photo_import.php):
 *   $theme (string)  — active theme slug (for data-theme attribute)
 *
 * No DB queries here. All AJAX calls go to api/photo_import/*.php.
 * CSS: css/photo-import.css (extracted TASK-19)
 * JS:  js/photo-import.js  (extracted TASK-19)
 */
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(function_exists('app_lang') ? app_lang() : 'cs') ?>" data-theme="<?= h($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lokalni import fotek — GPX Manager</title>

    <!-- CSRF token for AJAX requests (read by photo-import.js via meta[name=csrf-token]) -->
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">

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
    <link rel="stylesheet" href="css/photo-import.css">
</head>
<body>
<?php render_admin_banner(); ?>
<div class="imp-wrap">

<h1 class="imp-title">&#128229; Lokalni import fotek</h1>
<p class="imp-subtitle">Skenuje lokalni adresar na pocitaci, zobrazi nahled s EXIF daty a umozni hromadny import primo do projektu.</p>

<!-- Path input -->
<div class="imp-path-row">
    <input type="text" id="dirInput" placeholder="Cesta k adresari, napr. D:\Fotky\2024\Alpy\" autocomplete="off" spellcheck="false">
    <button class="btn-scan" id="btnScan">&#128269; Skenovat</button>
</div>
<div class="imp-recent" id="recentDirs"></div>

<!-- Loading indicator -->
<div id="impLoading" style="display:none;">
    <div class="imp-loading">
        <div class="imp-spinner"></div>
        <div>Skenuji adresar…</div>
    </div>
</div>

<!-- Gallery section -->
<div id="gallerySection" style="display:none;">

    <!-- Toolbar -->
    <div class="imp-toolbar">
        <div class="imp-stats" id="impStats">—</div>
        <button class="imp-filter-btn active" data-filter="all">Vse</button>
        <button class="imp-filter-btn" data-filter="new">Jen nove</button>
        <button class="imp-filter-btn" data-filter="gps">Jen GPS</button>
        <button class="imp-filter-btn" data-filter="gps_new">GPS + nove</button>
        <button class="imp-filter-btn" data-filter="track">Jen s trasou</button>
        <button class="imp-filter-btn" id="btnSelectAll">&#9745; Vybrat viditelne</button>
        <button class="imp-filter-btn" id="btnDeselectAll">&#9744; Zrusit vyber</button>
    </div>

    <!-- Import results (injected by JS) -->
    <div id="impResults"></div>

    <!-- Gallery cards (injected by JS) -->
    <div class="imp-gallery" id="impGallery"></div>

</div>

<!-- Sticky action bar -->
<div class="imp-action-bar" style="flex-wrap:wrap; gap:8px;">
    <div class="imp-sel-info">
        <span id="selCount">Vybrano: 0</span>
        <small id="selHint">— nejdriv naskenuj adresar</small>
    </div>
    <div id="barProgress" style="display:none;flex:1;min-width:200px;">
        <div class="imp-progress"><div class="imp-progress-bar" id="impProgressBar"></div></div>
        <div class="imp-progress-text" id="impProgressText"></div>
    </div>
    <a href="photos.php" style="font-size:13px;color:var(--text-muted);text-decoration:none;">&#8592; Galerie fotek</a>
    <a href="index.php" style="font-size:13px;color:var(--text-muted);text-decoration:none;">&#8592; Prehled tras</a>
    <button class="btn-import" id="btnImport" disabled>Importovat vybrane</button>
</div>

</div><!-- .imp-wrap -->

<script src="js/lib/format-utils.js"></script>
<script src="js/photo-import.js"></script>
</body>
</html>
