<?php
declare(strict_types=1);

/**
 * Mapa — jen mapa přes celou výšku okna, bez tras a fotek.
 * Slouží k hledání v kombinaci vrstev: každou vrstvu jde zapnout,
 * nastavit jí průhlednost a posunout výš nebo níž (js/map-page.js).
 */
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
check_page_access('map');

$allowedLangs = available_langs();

$page_title = t('nav_map', 'Mapa');
require __DIR__ . '/includes/layout_header.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H" crossorigin="anonymous">
<link rel="stylesheet" href="https://unpkg.com/leaflet.fullscreen@1.6.0/Control.FullScreen.css" integrity="sha384-weDCJ80JNrg6W2Dha8CBrQyz5PZVPOZ39Lw7vWOzm65zqKvZZfSq/3rR77RY5TWm" crossorigin="anonymous">
<link rel="stylesheet" href="<?= asset('css/map-page.css') ?>">

<h1 class="gpx-mp-sr"><?= h(t('nav_map', 'Mapa')) ?></h1>

<!-- Vlastní id místo #map: výšku #map řídí nastavení v Administraci,
     tahle stránka má mapu vždy přes celou výšku okna pod hlavičkou. -->
<div id="gpxMapPage"
     role="img"
     aria-label="<?= h(t('map_aria_generic', 'Interaktivní mapa')) ?>"></div>

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

<script src="<?= asset('js/lib/map-factory.js') ?>"></script>

<script nonce="<?= csp_nonce() ?>">
window.gpxMapPageData = {
    apiKeys: {
        tf:        <?= js_safe_json(TF_API_KEY) ?>,
        mapycom:   <?= js_safe_json(MAPYCOM_API_KEY) ?>,
        mapillary: <?= js_safe_json(MAPILLARY_TOKEN) ?>
    },
    i18n: <?= js_safe_json([
        'layers'   => t('mp_layers', 'Vrstvy'),
        'active'   => t('mp_active', 'Zapnuté vrstvy'),
        'none'     => t('mp_none', 'Žádná vrstva není zapnutá.'),
        'base'     => t('mp_base', 'Podkladové mapy'),
        'overlay'  => t('mp_overlay', 'Překryvné vrstvy'),
        'opacity'  => t('mp_opacity', 'Krytí'),
        'up'       => t('mp_up', 'Posunout výš'),
        'down'     => t('mp_down', 'Posunout níž'),
        'off'      => t('mp_off', 'Vypnout'),
        'collapse' => t('mp_collapse', 'Sbalit panel'),
        'expand'   => t('mp_expand', 'Rozbalit panel'),
    ]) ?>
};
</script>
<script defer src="<?= asset('js/map-page.js') ?>"></script>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
