<?php
declare(strict_types=1);
/**
 * planner_view.php — šablona Plánovače výšlapu.
 * Mapa + waypointy, profil routingu, statistiky, výškový profil (Chart.js
 * + Open-Meteo elevation), ukládání plánů, počasí pro plánovaný den,
 * odhad času z vlastního tempa, export GPX pro Garmin.
 */

// Osobní tempo z vlastních tras (medianu se blížící průměr s rozumnými mezemi).
// Pěší: Pěšky+Turistika, kolo: Kolo+E-bike. Použije se pro odhad času
// (Naismith: + stoupání 600 m/h pěšky, 800 m/h na kole).
$_paceFoot = null;
$_paceBike = null;
try {
    $_paceFoot = $pdo->query("
        SELECT AVG(speed_avg) FROM tracks
        WHERE activity_type IN ('Pěšky', 'Turistika')
          AND speed_avg BETWEEN 1 AND 10
    ")->fetchColumn();
    $_paceBike = $pdo->query("
        SELECT AVG(speed_avg) FROM tracks
        WHERE activity_type IN ('Kolo', 'E-bike')
          AND speed_avg BETWEEN 5 AND 45
    ")->fetchColumn();
} catch (\Throwable $e) { /* bez osobního tempa */ }
$_paceFoot = $_paceFoot ? round((float)$_paceFoot, 2) : null;
$_paceBike = $_paceBike ? round((float)$_paceBike, 2) : null;
?>
<?php
$page_title = t('h1_planner', 'Plánovač výšlapu');
require __DIR__ . '/layout_header.php';
?>
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/nearby.css">
<link rel="stylesheet" href="css/planner.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H" crossorigin="anonymous">
<link rel="stylesheet" href="https://unpkg.com/leaflet.fullscreen@1.6.0/Control.FullScreen.css" integrity="sha384-weDCJ80JNrg6W2Dha8CBrQyz5PZVPOZ39Lw7vWOzm65zqKvZZfSq/3rR77RY5TWm" crossorigin="anonymous">

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <a href="index.php" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
        <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i>
        <?= htmlspecialchars(t('back_to_list')) ?>
    </a>
    <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
        <i data-lucide="signpost" class="w-8 h-8 text-terracotta-500" aria-hidden="true"></i>
        <?= htmlspecialchars(t('h1_planner', 'Plánovač výšlapu')) ?>
    </h1>
</section>

<div class="mx-auto max-w-7xl px-4 sm:px-6 mt-6 pb-12">

<!-- Ovládání -->
<div class="plan-controls">
    <label for="planProfile"><?= htmlspecialchars(t('planner_profile', 'Profil')) ?>:
        <select id="planProfile">
            <optgroup label="Pěšky">
                <option value="foot_hiking" selected>🥾 turistická</option>
                <option value="foot_fast">🚶 pěšky nejkratší</option>
            </optgroup>
            <optgroup label="Kolo">
                <option value="bike_road">🚲 cyklostezka</option>
                <option value="bike_mountain">🚵 terénní cyklo</option>
            </optgroup>
            <optgroup label="Auto">
                <option value="car_fast">🚗 silnice (rychlá)</option>
                <option value="car_short">🚗 silnice (nejkratší)</option>
            </optgroup>
        </select>
    </label>
    <input type="text" id="planName" maxlength="80"
           placeholder="<?= htmlspecialchars(t('planner_name_placeholder', 'Název plánu (pro export)')) ?>">
    <button type="button" id="planUndo" class="plan-btn" disabled>↩ <?= htmlspecialchars(t('planner_undo', 'Zpět')) ?></button>
    <button type="button" id="planClear" class="plan-btn" disabled>✕ <?= htmlspecialchars(t('planner_clear', 'Vyčistit')) ?></button>
    <button type="button" id="planExport" class="plan-btn plan-btn-primary" disabled>💾 <?= htmlspecialchars(t('planner_export', 'Export GPX (Garmin)')) ?></button>
</div>

<!-- Uložené plány + datum výletu -->
<div class="plan-controls">
    <label for="planDate">📅 <?= htmlspecialchars(t('planner_date', 'Datum výletu')) ?>:
        <input type="date" id="planDate">
    </label>
    <button type="button" id="planSave" class="plan-btn" disabled>💾 <?= htmlspecialchars(t('planner_save', 'Uložit plán')) ?></button>
    <label for="planList">📂 <?= htmlspecialchars(t('planner_my_plans', 'Moje plány')) ?>:
        <select id="planList">
            <option value=""><?= htmlspecialchars(t('planner_select_plan', '— vyber plán —')) ?></option>
        </select>
    </label>
    <button type="button" id="planDelete" class="plan-btn" disabled>🗑 <?= htmlspecialchars(t('planner_delete', 'Smazat')) ?></button>
</div>

<!-- Stav -->
<div id="plan-status" class="nearby-status" aria-live="polite">
    <?= htmlspecialchars(t('planner_click_hint', 'Klikáním do mapy přidávej body — trasa se počítá po skutečných cestách. Body lze přetahovat, kliknutím na bod ho smažeš.')) ?>
</div>

<!-- Statistiky plánu -->
<div id="plan-stats" class="plan-stats" style="display:none;">
    <span>📏 <span id="planDist">–</span></span>
    <span>⏱️ <span id="planDur">–</span></span>
    <span id="planPaceWrap" style="display:none;">🚶 <?= htmlspecialchars(t('planner_my_pace', 'tvým tempem')) ?>: <span id="planPace">–</span></span>
    <span>⛰️ ↗ <span id="planAsc">–</span></span>
    <span>↘ <span id="planDesc">–</span></span>
    <span>📍 <span id="planWpts">0</span> <?= htmlspecialchars(t('planner_waypoints', 'bodů')) ?></span>
</div>

<!-- Počasí pro plánovaný den -->
<div id="plan-weather" class="plan-weather" style="display:none;"></div>

<!-- Mapa -->
<div id="map"
     role="img"
     aria-label="<?= htmlspecialchars(t('map_aria_generic', 'Interaktivní mapa')) ?>"></div>

<!-- Výškový profil -->
<div id="plan-elev-wrap" class="plan-elev-wrap" style="display:none;">
    <h2><?= htmlspecialchars(t('planner_elev', 'Výškový profil (odhad)')) ?></h2>
    <canvas id="planElevChart" height="120"
            aria-label="<?= htmlspecialchars(t('elev_chart_aria', 'Výškový profil trasy')) ?>" role="img"></canvas>
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
        src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"
        integrity="sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4"
        crossorigin="anonymous"></script>

<!-- Data z PHP pro JS -->
<script>
window.gpxPlannerData = {
    apiKeys: {
        tf:        <?= js_safe_json(TF_API_KEY) ?>,
        mapycom:   <?= js_safe_json(MAPYCOM_API_KEY) ?>,
        mapillary: <?= js_safe_json(MAPILLARY_TOKEN) ?>
    },
    csrfToken: <?= js_safe_json(csrf_token()) ?>,
    personalPace: {
        foot: <?= js_safe_json($_paceFoot) ?>,
        bike: <?= js_safe_json($_paceBike) ?>
    },
    i18n: {
        computing:  <?= js_safe_json(t('planner_computing', 'Počítám trasu…')) ?>,
        error:      <?= js_safe_json(t('planner_calc_error', 'Výpočet trasy selhal')) ?>,
        clickHint:  <?= js_safe_json(t('planner_click_hint', 'Klikáním do mapy přidávej body — trasa se počítá po skutečných cestách. Body lze přetahovat, kliknutím na bod ho smažeš.')) ?>,
        onePoint:   <?= js_safe_json(t('planner_one_point', 'Přidej další bod — trasa se spočítá mezi body.')) ?>,
        done:       <?= js_safe_json(t('planner_done', 'Trasa naplánována.')) ?>,
        saved:      <?= js_safe_json(t('planner_saved', 'Plán uložen.')) ?>,
        loaded:     <?= js_safe_json(t('planner_loaded', 'Plán načten.')) ?>,
        deleted:    <?= js_safe_json(t('planner_deleted', 'Plán smazán.')) ?>,
        saveError:  <?= js_safe_json(t('planner_save_error', 'Uložení selhalo')) ?>,
        nameNeeded: <?= js_safe_json(t('planner_name_needed', 'Zadej název plánu.')) ?>,
        confirmDel: <?= js_safe_json(t('planner_confirm_delete', 'Opravdu smazat vybraný plán?')) ?>,
        sunrise:    <?= js_safe_json(t('planner_sunrise', 'východ')) ?>,
        sunset:     <?= js_safe_json(t('planner_sunset', 'západ')) ?>,
        precip:     <?= js_safe_json(t('planner_precip', 'srážky')) ?>,
        wind:       <?= js_safe_json(t('planner_wind', 'vítr')) ?>
    }
};
</script>

<!-- Sdílené lib moduly — musí být načteny jako první -->
<script src="js/lib/event-bus.js"></script>
<script src="js/lib/geo-utils.js"></script>
<script src="js/lib/format-utils.js"></script>
<script src="js/lib/map-factory.js"></script>

<!-- JS modul stránky -->
<script src="js/planner.js"></script>

</div><?php require __DIR__ . '/layout_footer.php'; ?>
