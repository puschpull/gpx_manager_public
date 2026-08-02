<?php
/**
 * photo_import_view.php — HTML template for the local photo import page.
 *
 * No DB queries here. All AJAX calls go to api/photo_import/*.php.
 * CSS: css/photo-import.css (extracted TASK-19)
 * JS:  js/photo-import.js  (extracted TASK-19)
 */
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(function_exists('app_lang') ? app_lang() : 'cs') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lokalni import fotek — GPX Manager</title>

    <!-- CSRF token for AJAX requests (read by photo-import.js via meta[name=csrf-token]) -->
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">

    <?php // Tahle stránka nejede přes layout_header, takže si Inter musí načíst sama —
          // jinak by jako jediná zůstala v systémovém bezpatkovém písmu. ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="<?= asset('css/style.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/photo-import.css') ?>">
</head>
<body>
<?php // Samostatná stránka bez hlavičky webu — místo bývalé admin lišty stačí návrat zpět. ?>
<p style="margin:12px 16px 0;font-size:13px">
    <a href="index.php">&larr; <?= h(t('h1_my_tracks', 'Moje trasy')) ?></a>
</p>
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

<script src="<?= asset('js/lib/format-utils.js') ?>"></script>
<script src="<?= asset('js/photo-import.js') ?>"></script>
</body>
</html>
