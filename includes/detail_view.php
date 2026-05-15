<?php
/**
 * Detail trasy — Outdoor redesign.
 * Variables ze detail_data.php: $track, $track_name, $prevId, $nextId, $position, $total_nav,
 *                               $available_themes, $theme, $allowedLangs.
 *
 * Důležité: zachovává DOM ID (#map, #elev, #stats, #exportBtn, #qrBox, #qrToggleBtn,
 * #timeMode, #similar-wrap, #similar-tracks) aby JS moduly fungovaly beze změny.
 */
require_once __DIR__ . '/../includes/helpers.php';
$_isAdmin = !empty($_SESSION['is_admin']);
$page_title = $track['track_name'] ?: $track['filename'];

function fmtDur($sec) {
    $sec = (int)$sec;
    if ($sec <= 0) return '–';
    $h = intdiv($sec, 3600); $m = intdiv($sec % 3600, 60);
    return ($h > 0 ? $h . 'h ' : '') . $m . 'm';
}

// QR / share URL
$protocol = (defined('APP_ENV') && APP_ENV !== 'local') ? 'https' : ((!empty($_SERVER['HTTPS'])) ? 'https' : 'http');
$shareUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['PHP_SELF'], '?') . '?id=' . (int)$track['id'];
$qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . urlencode($shareUrl);

// Back URL preserving filter state + origin (index karty vs legacy tabulka)
$backQuery = $_GET; unset($backQuery['id']);
$origin = ($_GET['from'] ?? '') === 'legacy' ? 'index-legacy.php' : 'index.php';
unset($backQuery['from']); // 'from' nepatří do back URL
$backUrl = $origin . (!empty($backQuery) ? '?' . http_build_query($backQuery) : '');
$backLabel = $origin === 'index-legacy.php' ? 'Zpět na tabulku' : 'Zpět na seznam';

// Nav between tracks — zachovat filtry + origin
$navQuery = $_GET; unset($navQuery['id']);
$navQs = !empty($navQuery) ? '&' . http_build_query($navQuery) : '';
// (Pozn: 'from' a 'filter_submit' zůstávají, aby prev/next listoval ve stejném kontextu)

// Souřadnice středu trasy pro weather widget
$_bounds     = !empty($track['bounds']) ? json_decode($track['bounds'], true) : null;
$_weatherLat = $_bounds ? round(($_bounds['minlat'] + $_bounds['maxlat']) / 2, 5) : null;
$_weatherLon = $_bounds ? round(($_bounds['minlon'] + $_bounds['maxlon']) / 2, 5) : null;
$_hasWeather = !empty($track['date_start']) && $_weatherLat && $_weatherLon;

require __DIR__ . '/layout_header.php';
?>

<!-- Detail-specific styles (slope legend, tooltip toggle — FE-12 / TASK-24) -->
<link rel="stylesheet" href="css/detail.css">

<!-- Leaflet a fullscreen control CSS (jen pro detail) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet.fullscreen@1.6.0/Control.FullScreen.css">

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6 anim-fade-up anim-fade-up-1">
    <!-- Breadcrumb / back -->
    <a href="<?= h($backUrl) ?>" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
        <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i>
        <?= htmlspecialchars($backLabel) ?>
    </a>

    <!-- Title row -->
    <div class="flex items-start gap-4 flex-wrap">
        <!-- Metadata vlevo -->
        <div class="min-w-0 flex-1">
            <h1 class="font-[Manrope] text-2xl sm:text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 break-words">
                <?= h($track['track_name'] ?: $track['filename']) ?>
            </h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-forest-700/70 dark:text-sand-100/70">
                <?php if (!empty($track['date_start'])): ?>
                    <span class="inline-flex items-center gap-1">
                        <i data-lucide="calendar" class="w-3.5 h-3.5" aria-hidden="true"></i>
                        <?= date('d.m.Y', strtotime($track['date_start'])) ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($track['activity_type'])): ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-terracotta-500 text-white text-xs font-medium">
                        <?= h(activity_type_label($track['activity_type'])) ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($track['difficulty'])): ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-forest-100 dark:bg-forest-800 text-forest-700 dark:text-sand-100 text-xs font-medium">
                        <i data-lucide="bar-chart-3" class="w-3 h-3" aria-hidden="true"></i>
                        <?= htmlspecialchars(t('difficulty')) ?> <?= (int)$track['difficulty'] ?>/5
                    </span>
                <?php endif; ?>
                <?php if (!empty($track['filename'])): ?>
                    <span class="inline-flex items-center gap-1 text-xs opacity-70">
                        <i data-lucide="file" class="w-3 h-3" aria-hidden="true"></i>
                        <?= h($track['filename']) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Weather widget (uprostřed, skrytý dokud JS nenačte data) -->
        <?php if ($_hasWeather): ?>
        <div id="weather-section" class="hidden shrink-0">
            <div class="card-outdoor p-3 w-52">
                <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60 mb-2 pb-2 border-b border-sand-200 dark:border-forest-700">
                    <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>
                    Počasí
                    <span class="ml-auto normal-case opacity-60" title="Data z klimatického modelu ERA5 (~10 km rozlišení). Mohou se lišit od skutečnosti.">~ model</span>
                </div>
                <div id="weather-widget">
                    <!-- skeleton -->
                    <div class="flex items-center gap-2 mb-3">
                        <div class="skeleton w-7 h-7 rounded-full"></div>
                        <div class="space-y-1.5">
                            <div class="skeleton w-24 h-3 rounded"></div>
                            <div class="skeleton w-12 h-2.5 rounded"></div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-x-2 gap-y-1.5">
                        <div class="skeleton w-16 h-3 rounded"></div>
                        <div class="skeleton w-16 h-3 rounded"></div>
                        <div class="skeleton w-16 h-3 rounded"></div>
                        <div class="skeleton w-16 h-3 rounded"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Action buttons vpravo -->
        <div class="flex flex-wrap items-center gap-2 shrink-0">
            <?php if ($_isAdmin): ?>
                <a href="edit.php?id=<?= (int)$track['id'] ?>&<?= h(http_build_query($_GET)) ?>" class="btn-outdoor btn-outdoor-ghost">
                    <i data-lucide="pencil" class="w-4 h-4" aria-hidden="true"></i>
                    <?= htmlspecialchars(t('btn_edit_track')) ?>
                </a>
            <?php endif; ?>
            <a href="filter.php?id=<?= (int)$track['id'] ?><?= h($navQs) ?>" class="btn-outdoor btn-outdoor-ghost" title="<?= htmlspecialchars(t('btn_clean_track')) ?>">
                <i data-lucide="wand-2" class="w-4 h-4" aria-hidden="true"></i>
                <?= htmlspecialchars(t('btn_clean_track')) ?>
            </a>
        </div>
    </div>

    <!-- Prev/Next track nav -->
    <?php if ($prevId || $nextId): ?>
    <div class="mt-4 flex items-center gap-2 text-sm">
        <?php if ($prevId): ?>
            <a class="btn-outdoor btn-outdoor-ghost !py-1.5" href="detail.php?id=<?= $prevId ?><?= h($navQs) ?>">
                <i data-lucide="chevron-left" class="w-4 h-4" aria-hidden="true"></i>
                <?= htmlspecialchars(t('nav_prev')) ?>
            </a>
        <?php else: ?>
            <span class="btn-outdoor btn-outdoor-ghost !py-1.5 opacity-40 pointer-events-none">
                <i data-lucide="chevron-left" class="w-4 h-4" aria-hidden="true"></i>
                <?= htmlspecialchars(t('nav_prev')) ?>
            </span>
        <?php endif; ?>

        <?php if ($position !== null): ?>
            <span class="text-forest-700/60 dark:text-sand-100/60 px-2">
                <?= htmlspecialchars(t('track_position')) ?> <strong class="stat-num"><?= $position ?></strong> / <?= $total_nav ?>
            </span>
        <?php endif; ?>

        <?php if ($nextId): ?>
            <a class="btn-outdoor btn-outdoor-ghost !py-1.5" href="detail.php?id=<?= $nextId ?><?= h($navQs) ?>">
                <?= htmlspecialchars(t('nav_next')) ?>
                <i data-lucide="chevron-right" class="w-4 h-4" aria-hidden="true"></i>
            </a>
        <?php else: ?>
            <span class="btn-outdoor btn-outdoor-ghost !py-1.5 opacity-40 pointer-events-none">
                <?= htmlspecialchars(t('nav_next')) ?>
                <i data-lucide="chevron-right" class="w-4 h-4" aria-hidden="true"></i>
            </span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>

<!-- STAT CARDS GRID — A11Y-021: target for map skip link -->
<section id="track-stats" class="mx-auto max-w-7xl px-4 sm:px-6 mt-6 anim-fade-up anim-fade-up-2">
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <div class="card-outdoor p-4">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                <i data-lucide="ruler" class="w-3.5 h-3.5" aria-hidden="true"></i> <?= htmlspecialchars(t('th_distance')) ?>
            </div>
            <div class="mt-1 stat-num text-2xl font-semibold text-forest-700 dark:text-sand-100"><?= number_format((float)$track['distance_km'], 1, ',', ' ') ?></div>
            <div class="text-xs text-forest-700/60 dark:text-sand-100/60">km</div>
        </div>
        <div class="card-outdoor p-4">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                <i data-lucide="trending-up" class="w-3.5 h-3.5" aria-hidden="true"></i> <?= htmlspecialchars(t('th_ascent')) ?>
            </div>
            <div class="mt-1 stat-num text-2xl font-semibold text-forest-600"><?= number_format((int)$track['ascent'], 0, ',', ' ') ?></div>
            <div class="text-xs text-forest-700/60 dark:text-sand-100/60">m</div>
        </div>
        <div class="card-outdoor p-4">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                <i data-lucide="trending-down" class="w-3.5 h-3.5" aria-hidden="true"></i> <?= htmlspecialchars(t('th_descent')) ?>
            </div>
            <div class="mt-1 stat-num text-2xl font-semibold text-terracotta-500"><?= number_format((int)$track['descent'], 0, ',', ' ') ?></div>
            <div class="text-xs text-forest-700/60 dark:text-sand-100/60">m</div>
        </div>
        <div class="card-outdoor p-4">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                <i data-lucide="clock" class="w-3.5 h-3.5" aria-hidden="true"></i> <?= htmlspecialchars(t('th_duration')) ?>
            </div>
            <div class="mt-1 stat-num text-2xl font-semibold text-forest-700 dark:text-sand-100"><?= fmtDur($track['duration'] ?? 0) ?></div>
            <div class="text-xs text-forest-700/60 dark:text-sand-100/60">h:m</div>
        </div>
        <div class="card-outdoor p-4">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                <i data-lucide="gauge" class="w-3.5 h-3.5" aria-hidden="true"></i> <?= htmlspecialchars(t('th_speed_avg')) ?>
            </div>
            <div class="mt-1 stat-num text-2xl font-semibold text-forest-700 dark:text-sand-100"><?= number_format((float)($track['speed_avg'] ?? 0), 1, ',', ' ') ?></div>
            <div class="text-xs text-forest-700/60 dark:text-sand-100/60">km/h</div>
        </div>
        <div class="card-outdoor p-4">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                <i data-lucide="mountain-snow" class="w-3.5 h-3.5" aria-hidden="true"></i> <?= htmlspecialchars(t('th_elev_max')) ?>
            </div>
            <div class="mt-1 stat-num text-2xl font-semibold text-forest-700 dark:text-sand-100"><?= number_format((int)($track['elevation_max'] ?? 0), 0, ',', ' ') ?></div>
            <div class="text-xs text-forest-700/60 dark:text-sand-100/60">m n.m.</div>
        </div>
    </div>
</section>

<!-- MAP — A11Y-021: skip link + role="img" + aria-label -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-6">
    <a href="#track-stats" class="sr-only-focusable">
        <?= htmlspecialchars(t('skip_to_data', 'Přejít na data trasy')) ?>
    </a>
    <div id="map"
         role="img"
         aria-label="<?= htmlspecialchars(sprintf(t('map_aria', 'Mapa trasy: %s'), $track['track_name'] ?: $track['filename'])) ?>"
         class="w-full h-[60vh] min-h-[400px] rounded-2xl overflow-hidden shadow-card border border-sand-200 dark:border-forest-800"></div>
</section>

<!-- ACTIONS BAR -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-4">
    <div class="flex flex-wrap items-center gap-2">
        <button id="exportBtn" class="btn-outdoor btn-outdoor-ghost">
            <i data-lucide="image-down" class="w-4 h-4" aria-hidden="true"></i>
            <?= htmlspecialchars(t('btn_export_profile')) ?>
        </button>
        <a class="btn-outdoor btn-outdoor-ghost" href="export_kml.php?id=<?= (int)$track['id'] ?>" title="Google Earth / Google Maps / QGIS">
            <i data-lucide="download" class="w-4 h-4" aria-hidden="true"></i> KML
        </a>
        <a class="btn-outdoor btn-outdoor-ghost" href="export_geojson.php?id=<?= (int)$track['id'] ?>" title="QGIS, Leaflet, Mapbox">
            <i data-lucide="download" class="w-4 h-4" aria-hidden="true"></i> GeoJSON
        </a>
        <a class="btn-outdoor btn-outdoor-ghost" href="<?= h(gpx_url($track['filename'])) ?>" download>
            <i data-lucide="file-down" class="w-4 h-4" aria-hidden="true"></i> GPX
        </a>
        <button class="btn-outdoor btn-outdoor-ghost" id="qrToggleBtn" onclick="document.getElementById('qrBox').classList.toggle('qr-open')">
            <i data-lucide="qr-code" class="w-4 h-4" aria-hidden="true"></i> QR
        </button>

        <span class="ml-auto flex items-center gap-2">
            <label for="timeMode" class="text-sm text-forest-700/70 dark:text-sand-100/70">
                <?= htmlspecialchars(t('label_time_mode')) ?>
            </label>
            <select id="timeMode" class="select px-3 py-1.5 rounded-md bg-white dark:bg-forest-800 border border-sand-200 dark:border-forest-700 text-sm focus:border-forest-500 focus:outline-none">
                <option value="local" selected><?= htmlspecialchars(t('time_mode_local')) ?></option>
                <option value="utc"><?= htmlspecialchars(t('time_mode_utc')) ?></option>
            </select>
        </span>
    </div>

    <!-- QR box (toggle) -->
    <div id="qrBox" class="qr-box mt-3 p-4 rounded-xl bg-white dark:bg-forest-800 border border-sand-200 dark:border-forest-700 hidden flex-col items-center gap-2 max-w-sm">
        <img src="<?= h($qrApiUrl) ?>" alt="QR kód" width="180" height="180" loading="lazy" class="rounded-md">
        <p class="qr-url text-xs text-forest-700/70 dark:text-sand-100/70 break-all"><?= h($shareUrl) ?></p>
    </div>
</section>

<!-- ELEVATION PROFILE -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-6">
    <div class="card-outdoor p-4">
        <h2 class="font-[Manrope] text-lg font-semibold text-forest-700 dark:text-sand-100 mb-3 flex items-center gap-2">
            <i data-lucide="line-chart" class="w-5 h-5" aria-hidden="true"></i>
            <?= htmlspecialchars(t('h2_elevation')) ?>
        </h2>
        <div id="elev-wrap" class="relative">
            <div class="relative" style="height: 320px;">
                <!-- A11Y-022: role="img" + aria-label for Chart.js canvas -->
                <canvas id="elev"
                        role="img"
                        aria-label="<?= htmlspecialchars(t('elev_chart_aria', 'Výškový profil trasy')) ?>"></canvas>
            </div>
            <!-- A11Y-022: hidden data table fallback — populated by detail-elevation.js -->
            <details class="sr-only" id="elev-data-table-container">
                <summary><?= htmlspecialchars(t('elev_data_table', 'Tabulka dat výškového profilu')) ?></summary>
                <table id="elev-data-table">
                    <caption><?= htmlspecialchars(t('elev_data_caption', 'Hodnoty nadmořské výšky podél trasy')) ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?= htmlspecialchars(t('distance', 'Vzdálenost')) ?> (km)</th>
                            <th scope="col"><?= htmlspecialchars(t('elevation', 'Nadm. výška')) ?> (m)</th>
                        </tr>
                    </thead>
                    <tbody id="elev-data-tbody"></tbody>
                </table>
            </details>
            <div class="legend mt-2 text-xs text-forest-700/60 dark:text-sand-100/60">
                <?= htmlspecialchars(t('elev_legend_1')) ?>
                <?= htmlspecialchars(t('elev_legend_2')) ?> <strong><?= htmlspecialchars(t('elev_legend_3')) ?></strong> <?= htmlspecialchars(t('elev_legend_4')) ?>
            </div>
            <div class="badges mt-3 flex flex-wrap gap-2" id="stats"></div>
        </div>
    </div>
</section>

<!-- SIMILAR TRACKS -->
<section id="similar-wrap" class="mx-auto max-w-7xl px-4 sm:px-6 mt-8 mb-12 hidden">
    <h2 class="font-[Manrope] text-xl font-semibold text-forest-700 dark:text-sand-100 mb-4 flex items-center gap-2">
        <i data-lucide="git-fork" class="w-5 h-5" aria-hidden="true"></i>
        <?= htmlspecialchars(t('h2_similar_tracks')) ?>
    </h2>
    <div id="similar-tracks" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4"></div>
</section>

<!-- Inline QR-box show class (Tailwind cannot generate dynamic classes for `.qr-open`) -->
<style>
#qrBox.qr-open { display: flex !important; }
.leaflet-container { background: var(--color-sand-100); }
.dark .leaflet-container { background: var(--color-forest-800); }
</style>

<!-- JS knihovny -->
<!-- TODO: SRI hash via openssl dgst -sha384 -binary <url> | openssl base64 -A -->
<script defer
        src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity=""
        crossorigin="anonymous"></script>
<!-- TODO: SRI hash via openssl dgst -sha384 -binary <url> | openssl base64 -A -->
<script defer
        src="https://unpkg.com/leaflet.vectorgrid@1.3.0/dist/Leaflet.VectorGrid.bundled.js"
        integrity=""
        crossorigin="anonymous"></script>
<!-- TODO: SRI hash via openssl dgst -sha384 -binary <url> | openssl base64 -A -->
<script defer
        src="https://unpkg.com/leaflet.fullscreen@1.6.0/Control.FullScreen.js"
        integrity=""
        crossorigin="anonymous"></script>
<!-- TODO: SRI hash via openssl dgst -sha384 -binary <url> | openssl base64 -A -->
<script defer
        src="https://cdnjs.cloudflare.com/ajax/libs/leaflet-gpx/1.7.0/gpx.min.js"
        integrity=""
        crossorigin="anonymous"></script>
<!-- TODO: SRI hash via openssl dgst -sha384 -binary <url> | openssl base64 -A -->
<script defer
        src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"
        integrity=""
        crossorigin="anonymous"></script>
<!-- Lightbox pro fotky v mapových vrstvách "Moje fotografie" a "Polohy fotek na trase" -->
<script src="js/lightbox.js"></script>

<!-- Data z PHP pro JS -->
<script>
window.gpxDetailData = {
    trackId:      <?= js_safe_json($track ? (int)$track['id'] : null) ?>,
    dateStart:    <?= js_safe_json($track['date_start'] ?? null) ?>,
    weatherLat:   <?= js_safe_json($_weatherLat ?? null) ?>,
    weatherLon:   <?= js_safe_json($_weatherLon ?? null) ?>,
    fileNameEnc:  <?= js_safe_json(rawurlencode($track['filename'] ?? '')) ?>,
    fileNamePlain:<?= js_safe_json($track['filename'] ?? '') ?>,
    trackTitle:   <?= js_safe_json(($track['track_name'] ?: $track['filename']) ?? 'profil') ?>,
    gpxUrl:       <?= js_safe_json(gpx_url($track['filename'] ?? '')) ?>,
    dbAvgSpeed:   <?= js_safe_json(round($track['speed_avg']       ?? 0, 3)) ?>,
    dbAvgMoving:  <?= js_safe_json(round($track['speed_avg_total'] ?? 0, 3)) ?>,
    garminColor:  <?= js_safe_json($track['color'] ?? '') ?>,
    apiKeys: {
        tf:        <?= js_safe_json(defined('TF_API_KEY') ? TF_API_KEY : '') ?>,
        mapycom:   <?= js_safe_json(defined('MAPYCOM_API_KEY') ? MAPYCOM_API_KEY : '') ?>,
        mapillary: <?= js_safe_json(defined('MAPILLARY_TOKEN') ? MAPILLARY_TOKEN : '') ?>
    },
    i18n: {
        elev_label_elev:  <?= js_safe_json(t('elev_label_elev')) ?>,
        elev_label_dist:  <?= js_safe_json(t('elev_label_dist')) ?>,
        slope_steep_down: <?= js_safe_json(t('slope_steep_down')) ?>,
        slope_mild_down:  <?= js_safe_json(t('slope_mild_down')) ?>,
        slope_flat:       <?= js_safe_json(t('slope_flat')) ?>,
        slope_steep_up:   <?= js_safe_json(t('slope_steep_up')) ?>,
        colorful_tooltip: <?= js_safe_json(t('colorful_tooltip')) ?>
    }
};
</script>

<!-- Sdílené lib moduly — musí být načteny jako první -->
<script src="js/lib/event-bus.js"></script>
<script src="js/lib/geo-utils.js"></script>
<script src="js/lib/format-utils.js"></script>
<script src="js/lib/map-factory.js"></script>

<!-- JS moduly -->
<script src="js/detail-data.js"></script>
<script src="js/detail-map.js"></script>
<script src="js/detail-elevation.js"></script>
<script src="js/detail-ui.js"></script>
<script src="js/detail-weather.js"></script>

<!-- Podobné trasy -->
<script>
(function() {
    const trackId = <?= (int)$track['id'] ?>;

    // HTML escape for user-controlled fields rendered via innerHTML
    function escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, m => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[m]));
    }

    function formatDuration(s) {
        if (!s || s <= 0) return '–';
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        return h + ':' + String(m).padStart(2, '0');
    }
    function formatDate(d) {
        if (!d) return '–';
        const dt = new Date(d);
        if (isNaN(dt.getTime())) return d;
        return dt.toLocaleDateString('cs-CZ');
    }
    function difficultyDots(level) {
        if (!level || level < 1) return '–';
        const colors = ['', '#5b8a75', '#84ad94', '#e89455', '#c97b3f', '#a8443a'];
        const filled = '●'.repeat(level);
        const empty = '○'.repeat(5 - level);
        return '<span style="color:' + (colors[level] || '#999') + '">' + filled + empty + '</span>';
    }

    fetch('includes/detail_data.php?ajax=similar&id=' + trackId)
        .then(r => r.json())
        .then(data => {
            const tracks = data.tracks || [];
            if (tracks.length === 0) return;

            const wrap = document.getElementById('similar-wrap');
            const grid = document.getElementById('similar-tracks');
            if (!wrap || !grid) return;

            grid.innerHTML = '';
            tracks.forEach(t => {
                const thumbName = t.filename.replace(/\.[^.]+$/, '') + '.png';
                const card = document.createElement('a');
                card.href = 'detail.php?id=' + t.id;
                card.className = 'card-outdoor block p-3 group';
                card.innerHTML =
                    '<div class="aspect-[16/9] rounded-md overflow-hidden bg-gradient-to-br from-forest-400 to-forest-700 mb-3 relative">' +
                        '<img src="<?= h(uploads_url('thumbs/')) ?>' + encodeURIComponent(thumbName) + '" class="w-full h-full object-cover" alt="" onerror="this.style.display=\'none\'">' +
                    '</div>' +
                    '<div class="font-[Manrope] font-semibold text-forest-700 dark:text-sand-100 group-hover:text-terracotta-500 transition-colors line-clamp-1">' + escHtml(t.track_name || t.filename) + '</div>' +
                    '<div class="mt-1 text-sm text-forest-700/70 dark:text-sand-100/70">' +
                        '<span class="stat-num">' + escHtml(t.distance_km) + '</span> km · ↑<span class="stat-num">' + escHtml(t.ascent) + '</span> m · ' + escHtml(formatDuration(t.duration)) +
                    '</div>' +
                    '<div class="mt-0.5 text-xs text-forest-700/60 dark:text-sand-100/60">' +
                        formatDate(t.date_start) + ' · ' + difficultyDots(t.difficulty) +
                    '</div>';
                grid.appendChild(card);
            });

            wrap.classList.remove('hidden');
            if (window.lucide) lucide.createIcons();
        })
        .catch(err => console.error('Podobné trasy:', err));
})();
</script>

<?php require __DIR__ . '/layout_footer.php'; ?>
