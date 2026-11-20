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

// Stejné pravidlo jako check_page_access(): admin vidí vše, návštěvník
// jen stránky z Administrace → Viditelné stránky
$_isAdmin = !empty($_SESSION['is_admin']);
$_visiblePages = (array)get_app_config('visible_pages', all_pages());
$pageVisible = static fn(string $k): bool => $_isAdmin || in_array($k, $_visiblePages, true);

// Vrstva „Moje fotografie“ jen tam, kde smí návštěvník vidět i stránku Fotografie
$showPhotos = $pageVisible('photos');

// Vrstva „Moje značky“: admin vždy, návštěvník jen když nějaká značka je veřejná
$showMarkers = $_isAdmin;
if (!$showMarkers) {
    try {
        $showMarkers = (int)$pdo->query('SELECT COUNT(*) FROM map_markers WHERE is_public = 1')->fetchColumn() > 0;
    } catch (\PDOException $e) {
        $showMarkers = false;   // tabulka ještě není (migrace 0021 neproběhla)
    }
}
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
    showPhotos: <?= $showPhotos ? 'true' : 'false' ?>,
    showMarkers: <?= $showMarkers ? 'true' : 'false' ?>,
    isAdmin: <?= $_isAdmin ? 'true' : 'false' ?>,
    plannerVisible: <?= $pageVisible('planner') ? 'true' : 'false' ?>,
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
        'swipe'    => t('mp_swipe', 'Porovnávací posuvník'),
        'lens'     => t('mp_lens', 'Lupa'),
        'lensSize' => t('mp_lens_size', 'Velikost lupy'),
        'cmpNeed'  => t('mp_cmp_need', 'zapněte aspoň dvě vrstvy'),
        'copyLink'   => t('mp_copy_link', 'Zkopírovat odkaz'),
        'linkHint'   => t('mp_link_hint', ''),
        'linkCopied' => t('mp_link_copied', 'Odkaz zkopírován'),
        'linkFailed' => t('mp_link_failed', 'Zkopírujte adresu z adresního řádku'),
        'copy'        => t('mp_copy', 'Kopírovat'),
        'coordsHint'  => t('mp_coords_hint', ''),
        'measure'     => t('mp_measure', 'Měření vzdálenosti a plochy'),
        'measureHint' => t('mp_measure_hint', 'Klikáním do mapy přidávejte body'),
        'length'      => t('mp_length', 'Délka'),
        'area'        => t('mp_area', 'Plocha'),
        'undo'        => t('mp_undo', 'Zpět'),
        'clear'       => t('mp_clear', 'Smazat'),
        'presets'     => t('mp_presets', 'Uložené kombinace'),
        'preName'     => t('mp_pre_name', 'Název kombinace'),
        'preSave'     => t('mp_pre_save', 'Uložit současnou'),
        'preDel'      => t('mp_pre_del', 'Smazat kombinaci'),
        'preLocal'    => t('mp_pre_local', 'Ukládá se jen v tomto prohlížeči.'),
        'pre_hist'    => t('mp_pre_hist', 'Historická mapa a dnešek'),
        'pre_ortho'   => t('mp_pre_ortho', 'Letecké snímky 2001–2003 a dnes'),
        'pre_relief'  => t('mp_pre_relief', 'Turistická se stínováním reliéfu'),
        'pre_cad'     => t('mp_pre_cad', 'Katastr nad leteckou mapou'),
        'trackDetail' => t('mp_track_detail', 'Detail trasy'),
        'photoOpen'   => t('mp_photo_open', 'Otevřít fotku'),
        'addMarker'   => t('mp_add_marker', 'Přidat značku'),
        'markers'     => [
            'title'      => t('mp_mk_title', 'Název'),
            'note'       => t('mp_mk_note', 'Poznámka'),
            'symbol'     => t('mp_mk_symbol', 'Symbol'),
            'showPublic' => t('mp_mk_show_public', 'Ukázat i návštěvníkům'),
            'public'     => t('mp_mk_public', 'Vidí i návštěvníci'),
            'save'       => t('mp_mk_save', 'Uložit'),
            'cancel'     => t('mp_mk_cancel', 'Zrušit'),
            'edit'       => t('mp_mk_edit', 'Upravit'),
            'del'        => t('mp_mk_delete', 'Smazat'),
            'delConfirm' => t('mp_mk_del_confirm', 'Smazat značku?'),
            'failed'     => t('mp_mk_failed', 'Uložení se nepovedlo.'),
            'toPlanner'  => t('mp_mk_planner', 'Do plánovače'),
        ],
        'search'      => [
            'placeholder'       => t('mp_search', 'Hledat místo nebo souřadnice'),
            'placeholderCoords' => t('mp_search_coords_only', 'Zadat souřadnice'),
            'coords'            => t('mp_search_coords', 'Souřadnice'),
            'none'              => t('mp_search_none', 'Nic nenalezeno'),
            'error'             => t('mp_search_error', 'Hledání teď nefunguje'),
            'clearSearch'       => t('mp_search_clear', 'Zrušit hledání'),
        ],
    ]) ?>
};
</script>
<script defer src="<?= asset('js/map-page-compare.js') ?>"></script>
<script defer src="<?= asset('js/map-page-link.js') ?>"></script>
<script defer src="<?= asset('js/map-page-tools.js') ?>"></script>
<script defer src="<?= asset('js/map-page-presets.js') ?>"></script>
<script defer src="<?= asset('js/map-page-search.js') ?>"></script>
<script defer src="<?= asset('js/map-page-mine.js') ?>"></script>
<script defer src="<?= asset('js/map-page-markers.js') ?>"></script>
<script defer src="<?= asset('js/map-page.js') ?>"></script>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
