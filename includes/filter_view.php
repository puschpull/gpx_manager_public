<?php
require_once __DIR__ . '/../includes/helpers.php';
$_isAdmin = !empty($_SESSION['is_admin']);
?>

<?php
$page_title = t('h1_filter');
require __DIR__ . '/layout_header.php';
?>
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/filter.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet.fullscreen@1.6.0/Control.FullScreen.css">

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <?php
    // Zpět: respektovat původ (legacy tabulka vs kartový pohled vs detail)
    $_filterBackQ = $_GET;
    unset($_filterBackQ['id'], $_filterBackQ['from']);
    $_filterFromLegacy = ($_GET['from'] ?? '') === 'legacy';
    // Pokud máme 'id', nabídneme také zpět na detail
    $_filterDetailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $_filterDetailQs = $_filterDetailId
        ? '?id=' . $_filterDetailId . (!empty($_filterBackQ) ? '&' . http_build_query($_filterBackQ) . ($_filterFromLegacy ? '&from=legacy' : '') : ($_filterFromLegacy ? '&from=legacy' : ''))
        : '';
    $_filterListUrl  = ($_filterFromLegacy ? 'index-legacy.php' : 'index.php') .
                       (!empty($_filterBackQ) ? '?' . http_build_query($_filterBackQ) : '');
    $_filterListLabel = t($_filterFromLegacy ? 'back_to_table' : 'back_to_list');
    ?>
    <?php if ($_filterDetailId): ?>
        <a href="detail.php<?= h($_filterDetailQs) ?>" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-1">
            <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i>
            <?= htmlspecialchars(t('btn_show_on_map', 'Detail trasy')) ?>
        </a>
        <span class="text-forest-700/30 dark:text-sand-100/30 mx-1 mb-1">·</span>
    <?php endif; ?>
    <a href="<?= h($_filterListUrl) ?>" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
        <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i>
        <?= htmlspecialchars($_filterListLabel) ?>
    </a>
    <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
        <i data-lucide="wand-2" class="w-8 h-8 text-terracotta-500" aria-hidden="true"></i>
        <?= htmlspecialchars(t('h1_filter')) ?>
    </h1>
</section>

<div class="mx-auto max-w-7xl px-4 sm:px-6 mt-6 pb-12">


<?php if ($track): ?>
<div class="filter-db-banner">
    📂 Trasa načtena z databáze: <strong><?= h($track['track_name'] ?: $track['filename']) ?></strong>
    <?php if ($track['distance_km']): ?>
        &nbsp;·&nbsp; <?= number_format((float)$track['distance_km'], 1, ',', ' ') ?> km
    <?php endif; ?>
    <?php if ($track['ascent']): ?>
        &nbsp;·&nbsp; ↑ <?= number_format((float)$track['ascent'], 0, ',', ' ') ?> m
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Hlavni obsah -->
<div class="filter-layout">

    <!-- Levy panel - Filtry -->
    <aside class="filter-panel" id="filterPanel">
        <h2>Filtry</h2>

        <?php if (!$track): ?>
        <!-- Upload zona -->
        <div class="filter-section">
            <div id="dropzone" class="filter-dropzone">
                Pretahnete GPX soubor sem<br>nebo kliknete pro vyber
                <input type="file" id="fileInput" accept=".gpx" aria-label="<?= htmlspecialchars(t('filter_file_label', 'Vybrat GPX soubor pro filtraci')) ?>">
            </div>
        </div>
        <?php endif; ?>

        <!-- 1. Max rychlost -->
        <details class="filter-section" open>
            <summary>
                <label class="filter-toggle">
                    <input type="checkbox" id="f_speed_enabled" checked>
                    Max rychlost
                </label>
            </summary>
            <div class="filter-body">
                <div class="slider-row">
                    <label for="f_max_speed">Prah (km/h):</label>
                    <input type="range" id="f_max_speed" min="3" max="60" step="1" value="12">
                    <output for="f_max_speed" id="f_max_speed_val">12</output>
                </div>
                <div class="slider-row">
                    <label for="f_speed_window">Okno (body):</label>
                    <input type="range" id="f_speed_window" min="1" max="10" step="1" value="3">
                    <output for="f_speed_window" id="f_speed_window_val">3</output>
                </div>
                <p class="filter-hint">Odebere body kde prumerna rychlost presahuje prah. Zachyti jizdu autem.</p>
            </div>
        </details>

        <!-- 2. GPS skoky -->
        <details class="filter-section" open>
            <summary>
                <label class="filter-toggle">
                    <input type="checkbox" id="f_spike_enabled" checked>
                    GPS skoky / drift
                </label>
            </summary>
            <div class="filter-body">
                <div class="slider-row">
                    <label for="f_spike_distance">Vzdalenost (m):</label>
                    <input type="range" id="f_spike_distance" min="10" max="500" step="5" value="100">
                    <output for="f_spike_distance" id="f_spike_distance_val">100</output>
                </div>
                <div class="slider-row">
                    <label for="f_spike_time">Max cas (s):</label>
                    <input type="range" id="f_spike_time" min="1" max="60" step="1" value="10">
                    <output for="f_spike_time" id="f_spike_time_val">10</output>
                </div>
                <p class="filter-hint">Detekuje jednotlive body s nerealistickym skokem (GPS drift u budov).</p>
            </div>
        </details>

        <!-- 3. Stani na miste -->
        <details class="filter-section" open>
            <summary>
                <label class="filter-toggle">
                    <input type="checkbox" id="f_stationary_enabled" checked>
                    Stani na miste
                </label>
            </summary>
            <div class="filter-body">
                <div class="slider-row">
                    <label for="f_stationary_radius">Polomer (m):</label>
                    <input type="range" id="f_stationary_radius" min="3" max="50" step="1" value="15">
                    <output for="f_stationary_radius" id="f_stationary_radius_val">15</output>
                </div>
                <div class="slider-row">
                    <label for="f_stationary_time">Min doba (s):</label>
                    <input type="range" id="f_stationary_time" min="30" max="1800" step="30" value="300">
                    <output for="f_stationary_time" id="f_stationary_time_val">300</output>
                </div>
                <p class="filter-hint">Proredi shluky bodu kdyz se nestojite na miste prilis dlouho.</p>
            </div>
        </details>

        <!-- 4. Vyskove anomalie -->
        <details class="filter-section" open>
            <summary>
                <label class="filter-toggle">
                    <input type="checkbox" id="f_elevation_enabled" checked>
                    Vyskove anomalie
                </label>
            </summary>
            <div class="filter-body">
                <div class="slider-row">
                    <label for="f_elevation_spike">Max zmena (m):</label>
                    <input type="range" id="f_elevation_spike" min="5" max="100" step="1" value="30">
                    <output for="f_elevation_spike" id="f_elevation_spike_val">30</output>
                </div>
                <p class="filter-hint">Odebere body s nerealistickou vyskovou zmenou vuci obema sousedum.</p>
            </div>
        </details>

        <!-- 5. Kratke segmenty -->
        <details class="filter-section">
            <summary>
                <label class="filter-toggle">
                    <input type="checkbox" id="f_segment_enabled" checked>
                    Kratke segmenty
                </label>
            </summary>
            <div class="filter-body">
                <div class="slider-row">
                    <label for="f_min_segment_dist">Min vzdalenost (m):</label>
                    <input type="range" id="f_min_segment_dist" min="10" max="1000" step="10" value="200">
                    <output for="f_min_segment_dist" id="f_min_segment_dist_val">200</output>
                </div>
                <div class="slider-row">
                    <label for="f_min_segment_time">Min doba (s):</label>
                    <input type="range" id="f_min_segment_time" min="10" max="600" step="10" value="120">
                    <output for="f_min_segment_time" id="f_min_segment_time_val">120</output>
                </div>
                <p class="filter-hint">Po filtraci odstrani prilis kratke zbylove useky.</p>
            </div>
        </details>

        <!-- 6. Douglas-Peucker -->
        <details class="filter-section">
            <summary>
                <label class="filter-toggle">
                    <input type="checkbox" id="f_simplify_enabled">
                    Zjednoduseni trasy
                </label>
            </summary>
            <div class="filter-body">
                <div class="slider-row">
                    <label for="f_simplify_tolerance">Tolerance (m):</label>
                    <input type="range" id="f_simplify_tolerance" min="1" max="50" step="1" value="5">
                    <output for="f_simplify_tolerance" id="f_simplify_tolerance_val">5</output>
                </div>
                <p class="filter-hint">Douglas-Peucker: snizi pocet bodu pri zachovani tvaru trasy.</p>
            </div>
        </details>

        <hr class="filter-divider">

        <!-- Presety -->
        <div class="filter-section preset-section">
            <h3>Presety</h3>
            <div class="preset-controls">
                <select id="presetSelect" class="select">
                    <option value="">-- Vyberte preset --</option>
                </select>
                <div class="preset-buttons">
                    <button id="presetLoad" class="btn" title="Nacist preset">Nacist</button>
                    <button id="presetSave" class="btn" title="Ulozit soucasne nastaveni">Ulozit</button>
                    <button id="presetDelete" class="btn" title="Smazat preset">Smazat</button>
                </div>
            </div>
        </div>

        <hr class="filter-divider">

        <!-- Akce -->
        <div class="filter-actions">
            <button id="btnApply" class="btn btn-primary">▶ Aplikovat filtry</button>
            <button id="btnReset" class="btn">↺ Resetovat</button>
            <button id="btnExport" class="btn" disabled title="Stáhnout vyčištěný GPX soubor">⬇ Stáhnout GPX</button>
            <?php if ($track && $_isAdmin): ?>
            <button id="btnSaveCatalog" class="btn" disabled title="Uložit vyčištěnou verzi jako novou trasu v databázi (originál zůstane nedotčen)">💾 Uložit jako novou trasu</button>
            <?php endif; ?>
        </div>
    </aside>

    <!-- Pravy panel - Vizualizace -->
    <div class="filter-main" role="region" aria-label="<?= htmlspecialchars(t('filter_visualisation', 'Vizualizace filtrované trasy')) ?>">

        <!-- Statistiky pred/po -->
        <div class="filter-stats" id="filterStats">
            <div class="stat-group">
                <span class="stat-label">Body:</span>
                <span class="stat-orig" id="statPointsOrig">-</span>
                <span class="stat-arrow">→</span>
                <span class="stat-new" id="statPointsNew">-</span>
                <span class="stat-diff" id="statPointsDiff"></span>
            </div>
            <div class="stat-group">
                <span class="stat-label">Vzdalenost:</span>
                <span class="stat-orig" id="statDistOrig">-</span>
                <span class="stat-arrow">→</span>
                <span class="stat-new" id="statDistNew">-</span>
                <span class="stat-diff" id="statDistDiff"></span>
            </div>
            <div class="stat-group">
                <span class="stat-label">Prevyseni:</span>
                <span class="stat-orig" id="statAscentOrig">-</span>
                <span class="stat-arrow">→</span>
                <span class="stat-new" id="statAscentNew">-</span>
                <span class="stat-diff" id="statAscentDiff"></span>
            </div>
            <div class="stat-group">
                <span class="stat-label">Doba:</span>
                <span class="stat-orig" id="statDurationOrig">-</span>
                <span class="stat-arrow">→</span>
                <span class="stat-new" id="statDurationNew">-</span>
                <span class="stat-diff" id="statDurationDiff"></span>
            </div>
        </div>

        <!-- Mapa — A11Y-021: skip link + role="img" + aria-label -->
        <a href="#filterStats" class="sr-only-focusable">
            <?= htmlspecialchars(t('skip_to_data', 'Přejít na data trasy')) ?>
        </a>
        <div id="filterMap"
             role="img"
             aria-label="<?= htmlspecialchars(t('map_aria_generic', 'Interaktivní mapa')) ?>"></div>
        <div class="map-legend">
            <label class="map-legend-item">
                <input type="checkbox" id="layerOriginal" class="map-layer-cb">
                <span class="map-legend-line original"></span> Cela puvodni trasa
            </label>
            <label class="map-legend-item">
                <input type="checkbox" id="layerFiltered" class="map-layer-cb" checked>
                <span class="map-legend-line kept"></span> Vycistena trasa
            </label>
            <label class="map-legend-item">
                <input type="checkbox" id="layerRemoved" class="map-layer-cb" checked>
                <span class="map-legend-line removed"></span> Odfiltrovaná cast
            </label>
        </div>

        <!-- Vyskovy profil — A11Y-022: role="img" + aria-label + data table fallback -->
        <div id="filterElevWrap">
            <canvas id="filterElev"
                    role="img"
                    aria-label="<?= htmlspecialchars(t('elev_chart_aria', 'Výškový profil trasy')) ?>"></canvas>
        </div>
        <!-- A11Y-022: hidden data table for filterElev — populated by filter-elevation.js -->
        <details class="sr-only" id="filterElev-data-table-container">
            <summary><?= htmlspecialchars(t('elev_data_table', 'Tabulka dat výškového profilu')) ?></summary>
            <table id="filterElev-data-table">
                <caption><?= htmlspecialchars(t('elev_data_caption', 'Hodnoty nadmořské výšky podél trasy')) ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?= htmlspecialchars(t('distance', 'Vzdálenost')) ?> (km)</th>
                        <th scope="col"><?= htmlspecialchars(t('elevation', 'Nadm. výška')) ?> (m)</th>
                    </tr>
                </thead>
                <tbody id="filterElev-data-tbody"></tbody>
            </table>
        </details>

        <!-- Statistiky odstraneni dle filtru -->
        <div class="filter-removal-stats" id="removalStats">
            <h3>Odebrane body dle filtru</h3>
            <div class="removal-badges" id="removalBadges"></div>
        </div>

        <!-- Varování -->
        <div id="filterWarnings" class="filter-warnings" style="display:none;"></div>

    </div><!-- /.filter-main -->
</div>

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
        src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"
        integrity=""
        crossorigin="anonymous"></script>

<!-- Data z PHP pro JS -->
<script>
window.gpxFilterData = {
    trackId:      <?= js_safe_json($track ? (int)$track['id'] : null) ?>,
    gpxUrl:       <?= js_safe_json($track ? gpx_url($track['filename']) : null) ?>,
    fileName:     <?= js_safe_json($track ? $track['filename'] : null) ?>,
    trackName:    <?= js_safe_json($track ? ($track['track_name'] ?: $track['filename']) : null) ?>,
    csrfToken:    <?= js_safe_json(csrf_token()) ?>,
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
<script src="js/filter-core.js"></script>
<script src="js/filter-map.js"></script>
<script src="js/filter-elevation.js"></script>
<script src="js/filter-ui.js"></script>

<!-- A11Y-026: Accessible dialog for preset name — replaces browser prompt() -->
<dialog id="preset-name-dialog" aria-labelledby="preset-dialog-title" aria-describedby="preset-dialog-desc">
    <form method="dialog" id="preset-name-form" novalidate>
        <h2 id="preset-dialog-title"><?= htmlspecialchars(t('preset_save_title', 'Uložit preset')) ?></h2>
        <p id="preset-dialog-desc" class="dialog-desc"><?= htmlspecialchars(t('preset_save_desc', 'Zadejte název pro nový preset filtrů.')) ?></p>
        <label for="preset-name-input"><?= htmlspecialchars(t('preset_name_label', 'Název presetu')) ?></label>
        <input type="text" id="preset-name-input" name="preset_name"
               required maxlength="80"
               autocomplete="off"
               placeholder="<?= htmlspecialchars(t('preset_name_placeholder', 'Např. Horská trasa')) ?>">
        <p id="preset-name-error" role="alert" class="dialog-error" hidden></p>
        <div class="dialog-actions">
            <button type="submit" id="preset-dialog-confirm" class="btn btn-primary">
                <?= htmlspecialchars(t('save', 'Uložit')) ?>
            </button>
            <button type="button" id="preset-dialog-cancel" class="btn">
                <?= htmlspecialchars(t('cancel', 'Zrušit')) ?>
            </button>
        </div>
    </form>
</dialog>

</div><?php require __DIR__ . '/layout_footer.php'; ?>