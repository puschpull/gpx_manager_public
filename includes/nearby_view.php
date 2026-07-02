<?php
require_once __DIR__ . '/../includes/helpers.php';
?>

<?php
$page_title = t('h1_nearby');
require __DIR__ . '/layout_header.php';
?>
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/nearby.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H" crossorigin="anonymous">
<link rel="stylesheet" href="https://unpkg.com/leaflet.fullscreen@1.6.0/Control.FullScreen.css" integrity="sha384-weDCJ80JNrg6W2Dha8CBrQyz5PZVPOZ39Lw7vWOzm65zqKvZZfSq/3rR77RY5TWm" crossorigin="anonymous">

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <a href="index.php" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
        <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i>
        <?= htmlspecialchars(t('back_to_list')) ?>
    </a>
    <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
        <i data-lucide="map-pin" class="w-8 h-8 text-terracotta-500" aria-hidden="true"></i>
        <?= htmlspecialchars(t('h1_nearby')) ?>
    </h1>
</section>

<div class="mx-auto max-w-7xl px-4 sm:px-6 mt-6 pb-12">


<!-- Instrukce -->
<div id="nearby-status" class="nearby-status">
    Klikněte na mapu pro nalezení 8 nejbližších tras
</div>

<!-- Mapa — A11Y-021: skip link + role="img" + aria-label -->
<a href="#nearby-results" class="sr-only-focusable">
    <?= htmlspecialchars(t('skip_to_data', 'Přejít na data trasy')) ?>
</a>
<div id="map"
     role="img"
     aria-label="<?= htmlspecialchars(t('map_aria_generic', 'Interaktivní mapa')) ?>"></div>

<!-- Tabulka výsledků -->
<div id="nearby-results" class="nearby-results" style="display:none;">
    <h2>Nalezené nejbližší trasy</h2>
    <table class="nearby-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Barva</th>
                <th>Název trasy</th>
                <th>Vzdálenost od bodu (km)</th>
                <th>Délka trasy (km)</th>
                <th>Stoupání (m)</th>
                <th>Klesání (m)</th>
                <th>Datum</th>
                <th>Doba</th>
            </tr>
        </thead>
        <tbody id="nearby-tbody"></tbody>
    </table>
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
<script defer
        src="https://cdnjs.cloudflare.com/ajax/libs/leaflet-gpx/1.7.0/gpx.min.js"
        integrity="sha384-FlFKgUqOEwuywgVc0+0QrDWcRsIzuyedLe+yUpC1jG4WgtdhJGvWf9mKm6GShpJv"
        crossorigin="anonymous"></script>

<!-- Data z PHP pro JS -->
<script>
window.gpxNearbyData = {
    apiKeys: {
        tf:        <?= js_safe_json(TF_API_KEY) ?>,
        mapycom:   <?= js_safe_json(MAPYCOM_API_KEY) ?>,
        mapillary: <?= js_safe_json(MAPILLARY_TOKEN) ?>
    }
};
</script>

<!-- Sdílené lib moduly — musí být načteny jako první -->
<script src="js/lib/event-bus.js"></script>
<script src="js/lib/geo-utils.js"></script>
<script src="js/lib/format-utils.js"></script>
<script src="js/lib/map-factory.js"></script>

<!-- JS moduly -->
<script src="js/nearby-data.js"></script>
<script src="js/nearby-map.js"></script>

</div><?php require __DIR__ . '/layout_footer.php'; ?>