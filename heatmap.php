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
$cacheFile = __DIR__ . '/uploads/heatmap_cache.json';

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
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet.fullscreen@1.6.0/Control.FullScreen.css">
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

<!-- Leaflet.heat plugin -->
<!-- TODO: SRI hash via openssl dgst -sha384 -binary <url> | openssl base64 -A -->
<script defer
        src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"
        integrity=""
        crossorigin="anonymous"></script>

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

    // === Tile layers ===
    const keys = window.gpxHeatmapData.apiKeys;
    const tfApiKey       = keys.tf       || "";
    const mapyCOMApiKey  = keys.mapycom  || "";
    const mapillaryToken = keys.mapillary || "";

    const baseOSM = L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19, attribution: "© OpenStreetMap"
    });
    const baseTopo = L.tileLayer("https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png", {
        maxZoom: 17, attribution: "© OpenTopoMap"
    });
    const baseSat = L.tileLayer(
        "https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}",
        { maxZoom: 19, attribution: "© Esri" }
    );
    const baseMapyCOMBasic = L.tileLayer(
        `https://api.mapy.com/v1/maptiles/basic/256/{z}/{x}/{y}?apikey=${mapyCOMApiKey}`,
        { maxZoom: 19, attribution: '© <a href="https://mapy.com" target="_blank">Mapy.com</a>, © OpenStreetMap' }
    );
    const baseMapyCOMTurist = L.tileLayer(
        `https://api.mapy.com/v1/maptiles/outdoor/256/{z}/{x}/{y}?apikey=${mapyCOMApiKey}`,
        { maxZoom: 19, attribution: '© <a href="https://mapy.com" target="_blank">Mapy.com</a>, © OpenStreetMap' }
    );
    const baseMapyCOMWinter = L.tileLayer(
        `https://api.mapy.com/v1/maptiles/winter/256/{z}/{x}/{y}?apikey=${mapyCOMApiKey}`,
        { maxZoom: 19, attribution: '© <a href="https://mapy.com" target="_blank">Mapy.com</a>, © OpenStreetMap' }
    );
    const baseMapyCOMAerial = L.tileLayer(
        `https://api.mapy.com/v1/maptiles/aerial/256/{z}/{x}/{y}?apikey=${mapyCOMApiKey}`,
        { maxZoom: 20, attribution: '© <a href="https://mapy.com" target="_blank">Mapy.com</a>' }
    );
    const baseThunderforest = L.tileLayer(
        `https://{s}.tile.thunderforest.com/outdoors/{z}/{x}/{y}.png?apikey=${tfApiKey}`,
        { maxZoom: 22, attribution: "© Thunderforest, © OpenStreetMap" }
    );

    const overlayWaymarked = L.tileLayer(
        "https://tile.waymarkedtrails.org/hiking/{z}/{x}/{y}.png",
        { maxZoom: 19, opacity: 0.7, attribution: "© Waymarked Trails, © OpenStreetMap" }
    );

    let overlayMapillary = null;
    if (mapillaryToken && typeof L.vectorGrid !== "undefined") {
        overlayMapillary = L.vectorGrid.protobuf(
            `https://tiles.mapillary.com/maps/vtp/mly1_public/2/{z}/{x}/{y}?access_token=${mapillaryToken}`,
            {
                vectorTileLayerStyles: {
                    sequence: { weight: 2, color: "#05CB63", opacity: 0.8, fill: false },
                    image:    { radius: 3, fillColor: "#05CB63", fillOpacity: 0.8, color: "#fff", weight: 1, fill: true },
                    overview: { weight: 2, color: "#05CB63", opacity: 0.6, fill: false }
                },
                interactive: true, maxNativeZoom: 14
            }
        );
        overlayMapillary.on("click", e => {
            const props = e.layer.properties;
            if (props?.image_id) {
                window.open(`https://www.mapillary.com/app/?focus=photo&pKey=${props.image_id}`,
                    "mapillary_photo", "width=800,height=600,resizable=yes,scrollbars=yes");
            }
        });
    }

    const wikimediaMarkers = L.layerGroup();
    let wikimediaLoading = false;
    async function loadWikimediaPhotos() {
        if (wikimediaLoading || !map.hasLayer(wikimediaMarkers)) return;
        if (map.getZoom() < 12) { wikimediaMarkers.clearLayers(); return; }
        wikimediaLoading = true;
        const bounds = map.getBounds();
        const url = `https://commons.wikimedia.org/w/api.php?action=query&list=geosearch`
            + `&gscoord=${bounds.getCenter().lat}|${bounds.getCenter().lng}`
            + `&gsradius=10000&gslimit=50&gsnamespace=6&prop=imageinfo&iiprop=url|extmetadata&format=json&origin=*`;
        try {
            const res  = await fetch(url);
            const data = await res.json();
            wikimediaMarkers.clearLayers();
            const items = (data?.query?.geosearch || []).filter(i => i.lat && i.lon);
            const results = await Promise.all(items.map(item => {
                const u = `https://commons.wikimedia.org/w/api.php?action=query&titles=${encodeURIComponent(item.title)}`
                    + `&prop=imageinfo&iiprop=url|thumburl|extmetadata&iiurlwidth=400&format=json&origin=*`;
                return fetch(u).then(r => r.json()).then(d => ({ item, data: d })).catch(() => null);
            }));
            for (const result of results) {
                if (!result) continue;
                const { item, data: d } = result;
                const page = Object.values(d?.query?.pages || {})[0];
                const info = page?.imageinfo?.[0];
                if (!info) continue;
                const thumbUrl = (info.thumburl || info.url || "").replace(/["<>]/g, "");
                const title    = (page.title || "").replace("File:", "").replace(/[<>]/g, "");
                const icon = L.divIcon({ className: '', html: `<div class="wiki-marker">📷</div>`, iconSize: [28,28], iconAnchor: [14,14] });
                const popupEl = document.createElement("div");
                popupEl.style.cssText = "max-width:320px;font-size:13px;";
                const img = document.createElement("img");
                img.src = thumbUrl; img.style.cssText = "width:100%;border-radius:4px;margin-bottom:6px;";
                img.onerror = function() { this.style.display="none"; };
                popupEl.appendChild(img);
                const titleDiv = document.createElement("div");
                titleDiv.style.cssText = "font-weight:600;margin-bottom:4px;";
                titleDiv.textContent = title;
                popupEl.appendChild(titleDiv);
                const link = document.createElement("a");
                link.href = `https://commons.wikimedia.org/wiki/${encodeURIComponent(page.title)}`;
                link.target = "_blank"; link.style.cssText = "color:#0078d7;font-size:12px;";
                link.textContent = "Zobrazit na Wikimedia Commons →";
                popupEl.appendChild(link);
                wikimediaMarkers.addLayer(L.marker([item.lat, item.lon], { icon }).bindPopup(popupEl, { maxWidth: 340 }));
            }
        } catch(e) { console.error("Wikimedia chyba:", e); }
        finally { wikimediaLoading = false; }
    }
    map.on("moveend zoomend", () => { if (map.hasLayer(wikimediaMarkers)) loadWikimediaPhotos(); });

    const baseLayers = {
        "🗺️ OSM":                 baseOSM,
        "🏞️ Topo":                baseTopo,
        "🌍 Satelit (Esri)":      baseSat,
        "🗺️ Mapy.com základní":   baseMapyCOMBasic,
        "🧭 Mapy.com turistická": baseMapyCOMTurist,
        "❄️ Mapy.com zimní":      baseMapyCOMWinter,
        "✈️ Mapy.com letecká":    baseMapyCOMAerial,
        "🥾 Thunderforest":       baseThunderforest,
    };
    const overlayLayers = {
        "🥾 Turistické značení (Waymarked)": overlayWaymarked,
        ...(overlayMapillary ? { "📷 Fotografie (Mapillary)": overlayMapillary } : {}),
        "🖼️ Fotografie (Wikimedia)": wikimediaMarkers,
    };

    // Obnovit uloženou vrstvu
    const savedLayer = localStorage.getItem("gpx_map_layer");
    if (savedLayer && baseLayers[savedLayer]) {
        baseLayers[savedLayer].addTo(map);
    } else {
        baseOSM.addTo(map);
    }
    map.on("baselayerchange", e => { localStorage.setItem("gpx_map_layer", e.name); });

    L.control.layers(baseLayers, overlayLayers, { collapsed: true }).addTo(map);

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