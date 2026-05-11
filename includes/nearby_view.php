<?php
require_once __DIR__ . '/../includes/helpers.php';
?>

<?php
$page_title = t('h1_nearby');
require __DIR__ . '/layout_header.php';
?>
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/nearby.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet.fullscreen@1.6.0/Control.FullScreen.css">

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <a href="index.php" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
        <i data-lucide="arrow-left" class="w-4 h-4"></i>
        <?= htmlspecialchars(t('back_to_list')) ?>
    </a>
    <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
        <i data-lucide="map-pin" class="w-8 h-8 text-terracotta-500"></i>
        <?= htmlspecialchars(t('h1_nearby')) ?>
    </h1>
</section>

<div class="mx-auto max-w-7xl px-4 sm:px-6 mt-6 pb-12">


<!-- Instrukce -->
<div id="nearby-status" class="nearby-status">
    Klikněte na mapu pro nalezení 8 nejbližších tras
</div>

<!-- Mapa -->
<div id="map"></div>

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
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.vectorgrid@latest/dist/Leaflet.VectorGrid.bundled.js"></script>
<script src="https://unpkg.com/leaflet.fullscreen@1.6.0/Control.FullScreen.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet-gpx/1.7.0/gpx.min.js"></script>

<!-- Data z PHP pro JS -->
<script>
window.gpxNearbyData = {
    apiKeys: {
        tf:        <?= json_encode(TF_API_KEY) ?>,
        mapycom:   <?= json_encode(MAPYCOM_API_KEY) ?>,
        mapillary: <?= json_encode(MAPILLARY_TOKEN) ?>
    }
};
</script>

<!-- JS moduly -->
<script src="js/nearby-data.js"></script>
<script src="js/nearby-map.js"></script>

</div><?php require __DIR__ . '/layout_footer.php'; ?>