<?php
declare(strict_types=1);
/**
 * photo_nearby_view.php — šablona stránky „Fotografie v okolí".
 * Vzor: nearby_view.php (mapa + klik) + photo_heatmap (fotky na mapě + lightbox).
 */
?>
<?php
$page_title = t('h1_photo_nearby', 'Fotografie v okolí');
require __DIR__ . '/layout_header.php';
?>
<link rel="stylesheet" href="<?= asset('css/style.css') ?>">
<link rel="stylesheet" href="<?= asset('css/nearby.css') ?>">
<link rel="stylesheet" href="<?= asset('css/photo_nearby.css') ?>">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H" crossorigin="anonymous">
<link rel="stylesheet" href="https://unpkg.com/leaflet.fullscreen@1.6.0/Control.FullScreen.css" integrity="sha384-weDCJ80JNrg6W2Dha8CBrQyz5PZVPOZ39Lw7vWOzm65zqKvZZfSq/3rR77RY5TWm" crossorigin="anonymous">

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <a href="index.php" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
        <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i>
        <?= htmlspecialchars(t('back_to_list')) ?>
    </a>
    <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
        <i data-lucide="aperture" class="w-8 h-8 text-terracotta-500" aria-hidden="true"></i>
        <?= htmlspecialchars(t('h1_photo_nearby', 'Fotografie v okolí')) ?>
    </h1>
</section>

<div class="mx-auto max-w-7xl px-4 sm:px-6 mt-6 pb-12">

<!-- Ovládání: okruh + max. počet -->
<div class="pn-controls">
    <label for="pnRadius"><?= htmlspecialchars(t('pn_radius', 'Okruh')) ?>:
        <select id="pnRadius">
            <option value="5">5 km</option>
            <option value="10" selected>10 km</option>
            <option value="25">25 km</option>
            <option value="50">50 km</option>
        </select>
    </label>
    <label for="pnLimit"><?= htmlspecialchars(t('pn_limit', 'Max. fotek')) ?>:
        <select id="pnLimit">
            <option value="20">20</option>
            <option value="50" selected>50</option>
            <option value="100">100</option>
        </select>
    </label>
</div>

<!-- Stav -->
<div id="pn-status" class="nearby-status" aria-live="polite">
    <?= htmlspecialchars(t('pn_click_prompt', 'Klikněte na mapu — najdu nejbližší fotografie z vašich výletů.')) ?>
</div>

<!-- Mapa — skip link + role=img (vzor nearby, A11Y-021) -->
<a href="#pn-results" class="sr-only-focusable">
    <?= htmlspecialchars(t('skip_to_data', 'Přejít na data trasy')) ?>
</a>
<div id="map"
     role="img"
     aria-label="<?= htmlspecialchars(t('map_aria_generic', 'Interaktivní mapa')) ?>"></div>

<!-- Výsledky: mřížka fotek -->
<div id="pn-results" class="pn-results" style="display:none;">
    <h2><?= htmlspecialchars(t('pn_results', 'Nejbližší fotografie')) ?></h2>
    <div id="pn-grid" class="pn-grid"></div>
</div>

<!-- JS knihovny -->
<script defer
        src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH"
        crossorigin="anonymous"></script>
<script defer
        src="https://unpkg.com/leaflet.vectorgrid@1.3.0/dist/Leaflet.VectorGrid.bundled.js"
        integrity="sha384-FON5fTjCTtPuBgUS1r2H/PGXstH0Rk23YKjZmB6qITkbFqBcqtey/rPo9eXwOWpx"
        crossorigin="anonymous"></script>
<script defer
        src="https://unpkg.com/leaflet.fullscreen@1.6.0/Control.FullScreen.js"
        integrity="sha384-Kigx+fLsY5TWX5hU/QUxy7tQh2bUzeIuoHUZTj2O056ByEtnhW6gi9ib8h6r5yb8"
        crossorigin="anonymous"></script>

<!-- Data z PHP pro JS -->
<script>
window.gpxPhotoNearbyData = {
    apiKeys: {
        tf:        <?= js_safe_json(TF_API_KEY) ?>,
        mapycom:   <?= js_safe_json(MAPYCOM_API_KEY) ?>,
        mapillary: <?= js_safe_json(MAPILLARY_TOKEN) ?>
    },
    i18n: {
        searching: <?= js_safe_json(t('pn_searching', 'Hledám fotografie v okolí…')) ?>,
        found:     <?= js_safe_json(t('pn_found', 'Nalezeno fotografií: {n}')) ?>,
        none:      <?= js_safe_json(t('pn_none', 'V okolí zvoleného bodu nejsou žádné fotografie.')) ?>,
        error:     <?= js_safe_json(t('pn_error', 'Chyba při komunikaci se serverem.')) ?>,
        distance:  <?= js_safe_json(t('pn_distance', 'Vzdálenost')) ?>
    }
};
</script>

<!-- Sdílené lib moduly — musí být načteny jako první -->
<script src="<?= asset('js/lib/event-bus.js') ?>"></script>
<script src="<?= asset('js/lib/geo-utils.js') ?>"></script>
<script src="<?= asset('js/lib/format-utils.js') ?>"></script>
<script src="<?= asset('js/lib/map-factory.js') ?>"></script>
<script src="<?= asset('js/lightbox.js') ?>" defer></script>

<!-- JS modul stránky -->
<script src="<?= asset('js/photo-nearby.js') ?>"></script>

</div><?php require __DIR__ . '/layout_footer.php'; ?>
