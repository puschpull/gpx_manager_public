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
    <title><?= h(t('pimp_title', 'Lokální import fotek')) ?> — GPX Manager</title>

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

<h1 class="imp-title">&#128229; <?= h(t('pimp_title', 'Lokální import fotek')) ?></h1>
<p class="imp-subtitle"><?= h(t('pimp_subtitle', 'Skenuje lokální adresář na počítači, zobrazí náhled s EXIF daty a umožní hromadný import přímo do projektu.')) ?></p>

<!-- Path input -->
<div class="imp-path-row">
    <input type="text" id="dirInput" placeholder="<?= h(t('pimp_dir_placeholder', 'Cesta k adresáři, např. D:\Fotky\2024\Alpy\\')) ?>" autocomplete="off" spellcheck="false">
    <button class="btn-scan" id="btnScan">&#128269; <?= h(t('pimp_scan', 'Skenovat')) ?></button>
</div>
<div class="imp-recent" id="recentDirs"></div>

<!-- Loading indicator -->
<div id="impLoading" style="display:none;">
    <div class="imp-loading">
        <div class="imp-spinner"></div>
        <div><?= h(t('pimp_scanning_dir', 'Skenuji adresář…')) ?></div>
    </div>
</div>

<!-- Gallery section -->
<div id="gallerySection" style="display:none;">

    <!-- Toolbar -->
    <div class="imp-toolbar">
        <div class="imp-stats" id="impStats">—</div>
        <button class="imp-filter-btn active" data-filter="all"><?= h(t('pimp_f_all', 'Vše')) ?></button>
        <button class="imp-filter-btn" data-filter="new"><?= h(t('pimp_f_new', 'Jen nové')) ?></button>
        <button class="imp-filter-btn" data-filter="gps"><?= h(t('pimp_f_gps', 'Jen GPS')) ?></button>
        <button class="imp-filter-btn" data-filter="gps_new"><?= h(t('pimp_f_gps_new', 'GPS + nové')) ?></button>
        <button class="imp-filter-btn" data-filter="track"><?= h(t('pimp_f_track', 'Jen s trasou')) ?></button>
        <button class="imp-filter-btn" id="btnSelectAll">&#9745; <?= h(t('pimp_select_visible', 'Vybrat viditelné')) ?></button>
        <button class="imp-filter-btn" id="btnDeselectAll">&#9744; <?= h(t('pimp_deselect', 'Zrušit výběr')) ?></button>
    </div>

    <!-- Import results (injected by JS) -->
    <div id="impResults"></div>

    <!-- Gallery cards (injected by JS) -->
    <div class="imp-gallery" id="impGallery"></div>

</div>

<!-- Sticky action bar -->
<div class="imp-action-bar" style="flex-wrap:wrap; gap:8px;">
    <div class="imp-sel-info">
        <span id="selCount"><?= h(str_replace('{n}', '0', t('pimp_selected', 'Vybráno: {n}'))) ?></span>
        <small id="selHint">— <?= h(t('pimp_hint_scan_first', 'nejdřív naskenujte adresář')) ?></small>
    </div>
    <div id="barProgress" style="display:none;flex:1;min-width:200px;">
        <div class="imp-progress"><div class="imp-progress-bar" id="impProgressBar"></div></div>
        <div class="imp-progress-text" id="impProgressText"></div>
    </div>
    <a href="photos.php" style="font-size:13px;color:var(--text-muted);text-decoration:none;">&#8592; <?= h(t('pimp_gallery', 'Galerie fotek')) ?></a>
    <a href="index.php" style="font-size:13px;color:var(--text-muted);text-decoration:none;">&#8592; <?= h(t('pimp_tracks', 'Přehled tras')) ?></a>
    <button class="btn-import" id="btnImport" disabled><?= h(t('pimp_import', 'Importovat vybrané')) ?></button>
</div>

</div><!-- .imp-wrap -->

<script nonce="<?= csp_nonce() ?>">
window.gpxPhotoImportI18n = <?= js_safe_json([
    'recent'        => t('pimp_recent', 'Naposledy:'),
    'scanning'      => t('pimp_scanning', 'skenuji…'),
    'none_found'    => t('pimp_none_found', 'V adresáři nebyly nalezeny žádné fotky (JPEG/PNG/WebP).'),
    'loading_exif'  => t('pimp_loading_exif', 'načítám EXIF…'),
    'error'         => t('pimp_error', 'Chyba: {msg}'),
    'no_gps'        => t('pimp_no_gps', 'bez GPS'),
    'stat_found'    => t('pimp_stat_found', 'Nalezeno:'),
    'stat_gps'      => t('pimp_stat_gps', 'S GPS:'),
    'stat_new'      => t('pimp_stat_new', 'Nových:'),
    'stat_dups'     => t('pimp_stat_dups', 'Duplikátů:'),
    'selected'      => t('pimp_selected', 'Vybráno: {n}'),
    'import'        => t('pimp_import', 'Importovat vybrané'),
    'import_n'      => t('pimp_import_n', 'Importovat vybrané ({n})'),
    'results'       => t('pimp_results', 'Výsledky importu'),
    'progress'      => t('pimp_progress', 'zpracováno {done} / {total}…'),
    'done_ok'       => t('pimp_done_ok', 'Hotovo: ✅ importováno {n}'),
    'done_dups'     => t('pimp_done_dups', '⏭ duplikátů {n}'),
    'done_errors'   => t('pimp_done_errors', '❌ chyb {n}'),
    'show_new'      => t('pimp_show_new', '→ Zobrazit nové fotky v galerii ({n})'),
    'res_ok'        => t('pimp_res_ok', '✅ OK'),
    'res_assigned'  => t('pimp_res_assigned', 'Přiřazeno: {name}'),
    'res_no_track'  => t('pimp_res_no_track', '(bez přiřazené trasy)'),
    'res_shrunk'    => t('pimp_res_shrunk', 'zmenšeno o {pct} %'),
    'res_dup'       => t('pimp_res_dup', '⏭ Duplikát'),
    'res_dup_track' => t('pimp_res_dup_track', 'Již přiřazeno: {name}'),
    'res_dup_db'    => t('pimp_res_dup_db', 'Již v databázi'),
    'res_error'     => t('pimp_res_error', '❌ Chyba'),
]) ?>;
</script>
<script src="<?= asset('js/lib/format-utils.js') ?>"></script>
<script src="<?= asset('js/photo-import.js') ?>"></script>
</body>
</html>
