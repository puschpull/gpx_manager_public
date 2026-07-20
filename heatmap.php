<?php
/**
 * Heatmapa všech tras — zobrazí hustotu průchodu na mapě
 */
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/gpx_parser.php';
check_page_access('heatmap');

$allowedLangs = available_langs();

/* ===== Cache soubor pro heatmapu ===== */
$cacheFile = uploads_fs('heatmap_cache.json');

/* ===== AJAX endpoint — vrátí souřadnice ===== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'heatdata') {
    header('Content-Type: application/json; charset=utf-8');

    // Zkusit cache (platná 1 hodinu)
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        readfile($cacheFile);
        exit;
    }

    // Rychlý režim — body z bounds (střed + 4 rohy bounding boxu)
    // Bez parsování GPX souborů, okamžitá odpověď
    $tracks = $pdo->query("
        SELECT bounds FROM tracks
        WHERE bounds IS NOT NULL AND bounds != ''
    ")->fetchAll(PDO::FETCH_COLUMN);

    $points = [];
    foreach ($tracks as $boundsJson) {
        $b = json_decode($boundsJson, true);
        if (!$b || !isset($b['minlat'], $b['maxlat'], $b['minlon'], $b['maxlon'])) continue;

        // Střed trasy
        $centerLat = ($b['minlat'] + $b['maxlat']) / 2;
        $centerLon = ($b['minlon'] + $b['maxlon']) / 2;
        $points[] = [$centerLat, $centerLon];

        // 4 rohy pro lepší pokrytí
        $points[] = [$b['minlat'], $b['minlon']];
        $points[] = [$b['maxlat'], $b['maxlon']];
        $points[] = [$b['minlat'], $b['maxlon']];
        $points[] = [$b['maxlat'], $b['minlon']];

        // Mezibody na okrajích
        $points[] = [$centerLat, $b['minlon']];
        $points[] = [$centerLat, $b['maxlon']];
        $points[] = [$b['minlat'], $centerLon];
        $points[] = [$b['maxlat'], $centerLon];
    }

    $result = json_encode(['points' => $points, 'total_tracks' => count($tracks), 'mode' => 'fast']);

    // Uložit cache
    @file_put_contents($cacheFile, $result);

    echo $result;
    exit;
}

/* ===== Generování detailní cache z GPX souborů (spouští se ručně) ===== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'rebuild') {
    header('Content-Type: application/json; charset=utf-8');

    // SEC-011: Admin-only gate — visitors must not be able to trigger expensive rebuild
    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Admin only']);
        exit;
    }

    // CSRF verification — rebuild modifies the cache file on disk
    if (!csrf_verify()) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF']);
        exit;
    }

    // Rate limit: max 1 rebuild per hour (file-based marker in uploads/)
    $rebuildMarker = uploads_fs('_heatmap_rebuild.marker');
    if (is_file($rebuildMarker) && (time() - filemtime($rebuildMarker)) < 3600) {
        http_response_code(429);
        echo json_encode(['error' => 'Rebuild ran recently. Try again later.']);
        exit;
    }
    // Touch marker before the expensive work so concurrent requests also get 429
    @file_put_contents($rebuildMarker, (string)time());

    set_time_limit(600);
    ini_set('memory_limit', '512M');

    $tracks = $pdo->query("SELECT filename FROM tracks WHERE bounds IS NOT NULL AND bounds != ''")->fetchAll(PDO::FETCH_COLUMN);

    $points = [];
    $maxPointsPerTrack = 30; // silné podvzorkování pro 624+ tras
    $processed = 0;

    foreach ($tracks as $filename) {
        $gpxFile = uploads_fs($filename);
        if (!is_file($gpxFile)) continue;

        $xml = safe_load_gpx($gpxFile);
        if (!$xml) continue;

        $trackPoints = [];
        foreach ($xml->trk as $trk) {
            foreach ($trk->trkseg as $seg) {
                foreach ($seg->trkpt as $pt) {
                    $trackPoints[] = [(float)$pt['lat'], (float)$pt['lon']];
                }
            }
        }

        $count = count($trackPoints);
        if ($count <= $maxPointsPerTrack) {
            foreach ($trackPoints as $p) $points[] = $p;
        } else {
            $step = $count / $maxPointsPerTrack;
            for ($i = 0; $i < $maxPointsPerTrack; $i++) {
                $points[] = $trackPoints[(int)($i * $step)];
            }
        }
        $processed++;
    }

    $result = json_encode(['points' => $points, 'total_tracks' => $processed, 'mode' => 'detailed']);
    @file_put_contents($cacheFile, $result);

    echo $result;
    exit;
}
?>

<?php
$page_title = 'Heatmapa tras';
require __DIR__ . '/includes/layout_header.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H" crossorigin="anonymous">
<link rel="stylesheet" href="https://unpkg.com/leaflet.fullscreen@1.6.0/Control.FullScreen.css" integrity="sha384-weDCJ80JNrg6W2Dha8CBrQyz5PZVPOZ39Lw7vWOzm65zqKvZZfSq/3rR77RY5TWm" crossorigin="anonymous">
    <style>
        #map { height: calc(100vh - 200px) !important; min-height: 400px; }
        .heatmap-controls {
            display: flex;
            gap: 14px;
            align-items: center;
            flex-wrap: wrap;
            margin: 10px 0;
            padding: 10px 14px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
        }
        .heatmap-controls label {
            font-size: 13px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .heatmap-controls input[type="range"] {
            width: 120px;
        }
        .heatmap-status {
            font-size: 13px;
            color: var(--text-muted);
            margin: 8px 0;
        }
        /* Tlačítko musí být čitelné i s legacy tmavým tématem (--text-color funguje v obou systémech) */
        #rebuildBtn {
            color: var(--text-color);
            border-color: var(--border-color);
        }
    </style>

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <a href="index.php" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
        <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i>
        Zpět na seznam
    </a>
    <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
        <i data-lucide="flame" class="w-8 h-8 text-terracotta-500" aria-hidden="true"></i>
        Heatmapa tras
    </h1>
    <p class="mt-1 text-forest-700/70 dark:text-sand-100/70 text-sm">Hustota navštívených oblastí napříč všemi trasami</p>
</section>

<div class="mx-auto max-w-7xl px-4 sm:px-6 mt-6 pb-12">


<div class="heatmap-controls">
    <label>Poloměr: <input type="range" id="heatRadius" min="5" max="40" value="18"> <span id="radiusVal">18</span>px</label>
    <label>Intenzita: <input type="range" id="heatIntensity" min="0.1" max="1" step="0.05" value="0.5"> <span id="intensityVal">0.5</span></label>
    <label>Rozostření: <input type="range" id="heatBlur" min="5" max="30" value="15"> <span id="blurVal">15</span>px</label>
    <button class="btn-outdoor btn-outdoor-ghost" id="rebuildBtn" title="Přečte všechny GPX soubory a vytvoří detailnější heatmapu (může trvat minuty)">
        <i data-lucide="refresh-cw" class="w-4 h-4" aria-hidden="true"></i> Detailní heatmapa
    </button>
    <?php if (is_file($cacheFile)): ?>
        <span style="font-size:11px; color:var(--text-muted);">Cache: <?= date('d.m.Y H:i', filemtime($cacheFile)) ?></span>
    <?php endif; ?>
</div>

<div id="heatmap-status" class="heatmap-status" aria-live="polite">Načítání dat...</div>
<!-- A11Y-021: sr-only text summary + role="img" + aria-label; summary updated by JS -->
<p id="heatmap-sr-summary" class="sr-only" aria-live="polite"></p>
<a href="#heatmap-status" class="sr-only-focusable">
    <?= htmlspecialchars(t('skip_to_data', 'Přejít na data trasy')) ?>
</a>
<div id="map"
     role="img"
     aria-label="<?= htmlspecialchars(t('map_aria_generic', 'Interaktivní mapa')) ?>"></div>

<!-- Leaflet -->
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

<!-- Leaflet.heat plugin -->
<script defer
        src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"
        integrity="sha384-mFKkGiGvT5vo1fEyGCD3hshDdKmW3wzXW/x+fWriYJArD0R3gawT6lMvLboM22c0"
        crossorigin="anonymous"></script>

<!-- Sdílená map factory (geolokační tlačítko) -->
<script src="js/lib/map-factory.js"></script>

<script>
window.gpxHeatmapData = {
    apiKeys: {
        tf:        <?= js_safe_json(TF_API_KEY) ?>,
        mapycom:   <?= js_safe_json(MAPYCOM_API_KEY) ?>,
        mapillary: <?= js_safe_json(MAPILLARY_TOKEN) ?>
    },
    csrfToken: <?= js_safe_json(csrf_token()) ?>
};

document.addEventListener('DOMContentLoaded', () => {
    const map = L.map('map', { fullscreenControl: true }).setView([50.0, 14.5], 8);

    // === Vrstvy via shared factory (js/lib/map-factory.js) — všechny podklady
    //     i překryvy (Waymarked ×3, stínování, POI…) na jednom místě ===
    const keys = window.gpxHeatmapData.apiKeys;
    const { baseLayers, overlayLayers: _overlayBase, baseOSM } = window.GpxMapFactory.createBaseLayers(keys, map);
    const overlayMapillary = window.GpxMapFactory.createMapillaryOverlay(keys.mapillary || "");
    const wikimediaMarkers = window.GpxMapFactory.createWikimediaLayer(map);
    const overlayLayers = Object.assign({}, _overlayBase,
        overlayMapillary ? { "📷 Fotografie (Mapillary)": overlayMapillary } : {},
        { "🖼️ Fotografie (Wikimedia)": wikimediaMarkers }
    );

    // Obnovit uloženou vrstvu (factory přidala OSM automaticky)
    const savedLayer = localStorage.getItem("gpx_map_layer");
    if (savedLayer && baseLayers[savedLayer]) {
        map.removeLayer(baseOSM);
        baseLayers[savedLayer].addTo(map);
    }
    map.on("baselayerchange", e => { localStorage.setItem("gpx_map_layer", e.name); });
    map.on("overlayadd", e => { if (e.layer === wikimediaMarkers) wikimediaMarkers.loadPhotos(); });

    L.control.layers(baseLayers, overlayLayers, { collapsed: true }).addTo(map);
    if (window.GpxMapFactory.createLocateControl) window.GpxMapFactory.createLocateControl(map);

    // Load heatmap data
    let heatLayer = null;
    const statusEl = document.getElementById('heatmap-status');

    function loadHeatData(url, fetchOptions) {
        statusEl.textContent = 'Načítání dat...';

        fetch(url, fetchOptions || {})
            .then(r => {
                if (!r.ok) {
                    return r.json().then(e => { throw new Error(e.error || r.status); });
                }
                return r.json();
            })
            .then(data => {
                const modeLabel = data.mode === 'detailed' ? ' (detailní)' : ' (rychlý náhled)';
                statusEl.textContent =
                    `Načteno ${data.points.length.toLocaleString('cs')} bodů z ${data.total_tracks} tras${modeLabel}`;
                // A11Y-021: update sr-only summary for screen readers
                const srSummary = document.getElementById('heatmap-sr-summary');
                if (srSummary) {
                    srSummary.textContent = `Heatmapa zobrazuje ${data.total_tracks} tras${modeLabel}.`;
                }

                if (data.points.length === 0) return;

                // Fit bounds
                let minLat = 90, maxLat = -90, minLon = 180, maxLon = -180;
                data.points.forEach(p => {
                    if (p[0] < minLat) minLat = p[0];
                    if (p[0] > maxLat) maxLat = p[0];
                    if (p[1] < minLon) minLon = p[1];
                    if (p[1] > maxLon) maxLon = p[1];
                });
                map.fitBounds([[minLat, minLon], [maxLat, maxLon]], { padding: [30, 30] });

                // Odstranit starý layer
                if (heatLayer) { map.removeLayer(heatLayer); }

                // Create heat layer
                heatLayer = L.heatLayer(data.points, {
                    radius: parseInt(radiusSlider.value),
                    blur: parseInt(blurSlider.value),
                    maxZoom: 14,
                    max: parseFloat(intensitySlider.value),
                    gradient: {
                        0.2: '#0000ff',
                        0.4: '#00ffff',
                        0.6: '#00ff00',
                        0.8: '#ffff00',
                        1.0: '#ff0000'
                    }
                }).addTo(map);
            })
            .catch(err => {
                statusEl.textContent = 'Chyba při načítání: ' + err.message;
            });
    }

    // Controls
    const radiusSlider = document.getElementById('heatRadius');
    const intensitySlider = document.getElementById('heatIntensity');
    const blurSlider = document.getElementById('heatBlur');

    function updateHeat() {
        if (!heatLayer) return;
        const r = parseInt(radiusSlider.value);
        const i = parseFloat(intensitySlider.value);
        const b = parseInt(blurSlider.value);
        document.getElementById('radiusVal').textContent = r;
        document.getElementById('intensityVal').textContent = i;
        document.getElementById('blurVal').textContent = b;
        heatLayer.setOptions({ radius: r, blur: b, max: i });
    }

    radiusSlider.addEventListener('input', updateHeat);
    intensitySlider.addEventListener('input', updateHeat);
    blurSlider.addEventListener('input', updateHeat);

    // Tlačítko pro detailní rebuild (admin only — CSRF token required)
    document.getElementById('rebuildBtn').addEventListener('click', () => {
        if (!confirm('Toto přečte všechny GPX soubory a může trvat několik minut. Pokračovat?')) return;
        statusEl.textContent = 'Generuji detailní heatmapu — čtení GPX souborů...';
        loadHeatData('heatmap.php?ajax=rebuild', {
            headers: { 'X-CSRF-Token': window.gpxHeatmapData.csrfToken }
        });
    });

    // Načtení rychlých dat
    loadHeatData('heatmap.php?ajax=heatdata');
});
</script>

</div><?php require __DIR__ . '/includes/layout_footer.php'; ?>