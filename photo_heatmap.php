<?php
/**
 * Foto-heatmapa — hustota fotografií na mapě (z GPS v track_photos).
 * Při velkém přiblížení se nad heatmapou objeví klikatelné markery fotek
 * (klik → lightbox).
 */
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
check_page_access('photo_heatmap');

$allowedLangs = available_langs();

$_isAdmin = !empty($_SESSION['is_admin']);
$cacheFile = uploads_fs('photo_heatmap_cache.json');

/* ===== AJAX: body pro heatmapu ===== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'heatdata') {
    header('Content-Type: application/json; charset=utf-8');

    // Cache (1 h) — jen pro admina vždy čerstvá by byla zbytečná; visitor i admin
    // sdílí stejnou heatmapu hustoty. Nezahrnujeme neviditelné u návštěvníka.
    $visCond = $_isAdmin ? '' : 'AND (visible IS NULL OR visible = 1)';

    if ($_isAdmin && is_file($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        readfile($cacheFile);
        exit;
    }

    $rows = $pdo->query("
        SELECT lat, lon FROM track_photos
        WHERE lat IS NOT NULL AND lon IS NOT NULL $visCond
    ")->fetchAll(PDO::FETCH_NUM);

    $points = [];
    foreach ($rows as $r) {
        $points[] = [(float)$r[0], (float)$r[1]];
    }

    $result = json_encode(['points' => $points, 'total_photos' => count($points)], JSON_UNESCAPED_SLASHES);
    if ($_isAdmin) {
        @file_put_contents($cacheFile, $result);
    }
    echo $result;
    exit;
}

/* ===== AJAX: jednotlivé fotky v aktuálním výřezu (markery při zoomu) ===== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'photos') {
    header('Content-Type: application/json; charset=utf-8');

    $n = (float)($_GET['n'] ?? 0);
    $s = (float)($_GET['s'] ?? 0);
    $e = (float)($_GET['e'] ?? 0);
    $w = (float)($_GET['w'] ?? 0);
    if ($n == 0 && $s == 0) { echo json_encode(['photos' => []]); exit; }

    $visCond = $_isAdmin ? '' : 'AND (p.visible IS NULL OR p.visible = 1)';
    $stmt = $pdo->prepare("
        SELECT p.id, p.filename, p.orig_name, p.caption, p.taken_at,
               p.lat, p.lon, p.track_id, t.track_name
        FROM track_photos p
        LEFT JOIN tracks t ON t.id = p.track_id
        WHERE p.lat IS NOT NULL AND p.lon IS NOT NULL
          AND p.lat BETWEEN :s AND :n
          AND p.lon BETWEEN :w AND :e
          $visCond
        ORDER BY p.taken_at ASC, p.id ASC
        LIMIT 500
    ");
    $stmt->execute([':n' => $n, ':s' => $s, ':e' => $e, ':w' => $w]);

    $photos = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $photos[] = [
            'id'         => (int)$p['id'],
            'lat'        => (float)$p['lat'],
            'lon'        => (float)$p['lon'],
            'thumb_url'  => photo_thumb_url($p['filename']),
            'full_url'   => photo_full_url($p['filename']),
            'caption'    => $p['caption'],
            'taken_at'   => $p['taken_at'],
            'filename'   => $p['orig_name'] ?: $p['filename'],
            'track_id'   => $p['track_id'] ? (int)$p['track_id'] : null,
            'track_name' => $p['track_name'],
        ];
    }
    echo json_encode(['photos' => $photos], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===== AJAX: všechny tečky (lat, lon, id) — pro vrstvu "Polohy fotek" ===== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'dots') {
    header('Content-Type: application/json; charset=utf-8');
    $visCond = $_isAdmin ? '' : 'AND (visible IS NULL OR visible = 1)';
    $rows = $pdo->query("
        SELECT lat, lon, id FROM track_photos
        WHERE lat IS NOT NULL AND lon IS NOT NULL $visCond
    ")->fetchAll(PDO::FETCH_NUM);
    $dots = [];
    foreach ($rows as $r) {
        $dots[] = [(float)$r[0], (float)$r[1], (int)$r[2]];
    }
    echo json_encode(['dots' => $dots], JSON_UNESCAPED_SLASHES);
    exit;
}

/* ===== AJAX: detail jedné fotky podle id (pro lightbox z tečky) ===== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'photo') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['error' => 'bad id']); exit; }
    $visCond = $_isAdmin ? '' : 'AND (visible IS NULL OR visible = 1)';
    $stmt = $pdo->prepare("
        SELECT id, filename, orig_name, caption, taken_at
        FROM track_photos WHERE id = :id $visCond LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) { echo json_encode(['error' => 'not found']); exit; }
    echo json_encode(['photo' => [
        'full_url' => photo_full_url($p['filename']),
        'caption'  => $p['caption'],
        'taken_at' => $p['taken_at'],
        'filename' => $p['orig_name'] ?: $p['filename'],
    ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
?>

<?php
$page_title = 'Foto-heatmapa';
require __DIR__ . '/includes/layout_header.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H" crossorigin="anonymous">
<link rel="stylesheet" href="https://unpkg.com/leaflet.fullscreen@1.6.0/Control.FullScreen.css" integrity="sha384-weDCJ80JNrg6W2Dha8CBrQyz5PZVPOZ39Lw7vWOzm65zqKvZZfSq/3rR77RY5TWm" crossorigin="anonymous">
<link rel="stylesheet" href="css/style.css">
<script src="js/lib/format-utils.js" defer></script>
<script src="js/lightbox.js" defer></script>
<style>
    #map { height: calc(100vh - 200px) !important; min-height: 400px; }
    .heatmap-controls {
        display: flex; gap: 14px; align-items: center; flex-wrap: wrap;
        margin: 10px 0; padding: 10px 14px;
        background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px;
    }
    .heatmap-controls label { font-size: 13px; color: var(--text-muted); display: flex; align-items: center; gap: 6px; }
    .heatmap-controls input[type="range"] { width: 120px; }
    .heatmap-status { font-size: 13px; color: var(--text-muted); margin: 8px 0; }
    .photo-pin {
        width: 30px; height: 30px; border-radius: 50% 50% 50% 0;
        background: var(--accent-color, #2d4a3e); transform: rotate(-45deg);
        border: 2px solid #fff; box-shadow: 0 1px 4px rgba(0,0,0,.4);
        background-size: cover; background-position: center;
    }
</style>

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <a href="index.php" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
        <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i>
        Zpět na seznam
    </a>
    <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
        <i data-lucide="camera" class="w-8 h-8 text-terracotta-500" aria-hidden="true"></i>
        Foto-heatmapa
    </h1>
    <p class="mt-1 text-forest-700/70 dark:text-sand-100/70 text-sm">Hustota fotografií napříč všemi výlety — přibliž pro zobrazení jednotlivých fotek</p>
</section>

<div class="mx-auto max-w-7xl px-4 sm:px-6 mt-6 pb-12">

<div class="heatmap-controls">
    <label>Poloměr: <input type="range" id="heatRadius" min="5" max="40" value="18"> <span id="radiusVal">18</span>px</label>
    <label>Intenzita: <input type="range" id="heatIntensity" min="0.1" max="1" step="0.05" value="0.5"> <span id="intensityVal">0.5</span></label>
    <label>Rozostření: <input type="range" id="heatBlur" min="5" max="30" value="15"> <span id="blurVal">15</span>px</label>
</div>

<div id="heatmap-status" class="heatmap-status" aria-live="polite">Načítání dat...</div>
<div id="map" role="img" aria-label="Foto-heatmapa"></div>

<script defer src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH" crossorigin="anonymous"></script>
<script defer src="https://unpkg.com/leaflet.vectorgrid@1.3.0/dist/Leaflet.VectorGrid.bundled.js" integrity="sha384-FON5fTjCTtPuBgUS1r2H/PGXstH0Rk23YKjZmB6qITkbFqBcqtey/rPo9eXwOWpx" crossorigin="anonymous"></script>
<script defer src="https://unpkg.com/leaflet.fullscreen@1.6.0/Control.FullScreen.js" integrity="sha384-Kigx+fLsY5TWX5hU/QUxy7tQh2bUzeIuoHUZTj2O056ByEtnhW6gi9ib8h6r5yb8" crossorigin="anonymous"></script>
<script defer src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js" integrity="sha384-mFKkGiGvT5vo1fEyGCD3hshDdKmW3wzXW/x+fWriYJArD0R3gawT6lMvLboM22c0" crossorigin="anonymous"></script>
<script src="js/lib/map-factory.js"></script>

<script>
window.gpxHeatmapData = {
    apiKeys: {
        tf:        <?= js_safe_json(TF_API_KEY) ?>,
        mapycom:   <?= js_safe_json(MAPYCOM_API_KEY) ?>,
        mapillary: <?= js_safe_json(MAPILLARY_TOKEN) ?>
    }
};

document.addEventListener('DOMContentLoaded', () => {
    // preferCanvas: tečky (až 9k+ circleMarkerů) se kreslí přes canvas → plynulé
    const map = L.map('map', { fullscreenControl: true, preferCanvas: true }).setView([50.0, 14.5], 8);
    const dotsRenderer = L.canvas({ padding: 0.5 });

    // === Vrstvy via shared factory (js/lib/map-factory.js) ===
    const keys = window.gpxHeatmapData.apiKeys;
    const { baseLayers, overlayLayers: _overlayBase, baseOSM } = window.GpxMapFactory.createBaseLayers(keys, map);
    const overlayMapillary = window.GpxMapFactory.createMapillaryOverlay(keys.mapillary || "");

    // === Vrstvy fotek ===
    //  - Heatmapa hustoty (přepínatelná vrstva, výchozí ZAP)
    //  - Tečky: všechny fotky jako malé červené body (celý dataset, libovolný zoom)
    //  - Náhledy: piny s miniaturou jen v aktuálním výřezu při přiblížení (zoom 14+)
    const heatLayer = L.heatLayer([], {
        radius: 18, blur: 15, maxZoom: 14, max: 0.5,
        gradient: { 0.2:'#0000ff', 0.4:'#00ffff', 0.6:'#00ff00', 0.8:'#ffff00', 1.0:'#ff0000' }
    });
    const photoDots    = L.layerGroup();   // tečky (všechny)
    const photoMarkers = L.layerGroup();   // náhledy (výřez)

    const overlayLayers = {
        "🔥 Heatmapa hustoty": heatLayer,
        "📍 Polohy fotek – tečky": photoDots,
        "📸 Moje fotky – náhledy (při přiblížení)": photoMarkers,
        ..._overlayBase,
        ...(overlayMapillary ? { "📷 Fotografie (Mapillary)": overlayMapillary } : {}),
    };

    // Obnovit uloženou základní vrstvu (factory přidala OSM automaticky)
    const savedLayer = localStorage.getItem("gpx_map_layer");
    if (savedLayer && baseLayers[savedLayer]) {
        map.removeLayer(baseOSM);
        baseLayers[savedLayer].addTo(map);
    }
    map.on("baselayerchange", e => { localStorage.setItem("gpx_map_layer", e.name); });

    // Výchozí stav: heatmapa zapnutá
    heatLayer.addTo(map);

    L.control.layers(baseLayers, overlayLayers, { collapsed: true }).addTo(map);
    if (window.GpxMapFactory && window.GpxMapFactory.createLocateControl) window.GpxMapFactory.createLocateControl(map);

    const statusEl = document.getElementById('heatmap-status');
    const radiusSlider = document.getElementById('heatRadius');
    const intensitySlider = document.getElementById('heatIntensity');
    const blurSlider = document.getElementById('heatBlur');

    // === Heatmapa: naplnění dat ===
    fetch('photo_heatmap.php?ajax=heatdata')
        .then(r => r.json())
        .then(data => {
            statusEl.textContent = `Načteno ${data.total_photos.toLocaleString('cs')} fotografií`;
            if (!data.points.length) { statusEl.textContent = 'Žádné fotky s GPS.'; return; }

            let minLat=90, maxLat=-90, minLon=180, maxLon=-180;
            data.points.forEach(p => {
                if (p[0]<minLat) minLat=p[0]; if (p[0]>maxLat) maxLat=p[0];
                if (p[1]<minLon) minLon=p[1]; if (p[1]>maxLon) maxLon=p[1];
            });
            map.fitBounds([[minLat, minLon], [maxLat, maxLon]], { padding: [30, 30] });
            heatLayer.setLatLngs(data.points);
        })
        .catch(err => { statusEl.textContent = 'Chyba při načítání: ' + err.message; });

    function updateHeat() {
        const r = parseInt(radiusSlider.value), i = parseFloat(intensitySlider.value), b = parseInt(blurSlider.value);
        document.getElementById('radiusVal').textContent = r;
        document.getElementById('intensityVal').textContent = i;
        document.getElementById('blurVal').textContent = b;
        heatLayer.setOptions({ radius: r, blur: b, max: i });
    }
    radiusSlider.addEventListener('input', updateHeat);
    intensitySlider.addEventListener('input', updateHeat);
    blurSlider.addEventListener('input', updateHeat);

    // === Tečky: všechny fotky (jednorázové načtení, canvas) ===
    let dotsLoaded = false;
    function lightboxSingleById(id) {
        if (!window.gpxLightbox) return;
        fetch('photo_heatmap.php?ajax=photo&id=' + id)
            .then(r => r.json())
            .then(d => {
                if (d.photo) window.gpxLightbox.open([d.photo], 0);
            })
            .catch(() => {});
    }
    function ensureDots() {
        if (dotsLoaded) return;
        dotsLoaded = true;
        fetch('photo_heatmap.php?ajax=dots')
            .then(r => r.json())
            .then(data => {
                (data.dots || []).forEach(d => {
                    const c = L.circleMarker([d[0], d[1]], {
                        renderer: dotsRenderer,
                        radius: 4, color: "#e91e63", fillColor: "#e91e63",
                        fillOpacity: 0.85, weight: 1
                    });
                    c.on('click', () => lightboxSingleById(d[2]));
                    photoDots.addLayer(c);
                });
            })
            .catch(() => { dotsLoaded = false; });
    }
    map.on('overlayadd', e => { if (e.layer === photoDots) ensureDots(); });

    // === Náhledy: piny v aktuálním výřezu při přiblížení (zoom 14+) ===
    const THUMB_ZOOM_MIN = 14;
    let loadedPhotos = [];
    let photoLoading = false;

    function renderThumbs() {
        photoMarkers.clearLayers();
        if (!map.hasLayer(photoMarkers) || map.getZoom() < THUMB_ZOOM_MIN) return;
        loadedPhotos.forEach((p, idx) => {
            const icon = L.divIcon({
                className: '',
                html: `<div class="photo-pin" style="background-image:url('${p.thumb_url}')"></div>`,
                iconSize: [30, 30], iconAnchor: [15, 30]
            });
            const m = L.marker([p.lat, p.lon], { icon });
            m.on('click', () => {
                if (window.gpxLightbox) {
                    window.gpxLightbox.open(loadedPhotos.map(ph => ({
                        full_url: ph.full_url, caption: ph.caption,
                        taken_at: ph.taken_at, filename: ph.filename
                    })), idx);
                }
            });
            photoMarkers.addLayer(m);
        });
    }
    function loadThumbs() {
        if (!map.hasLayer(photoMarkers) || map.getZoom() < THUMB_ZOOM_MIN) {
            loadedPhotos = []; renderThumbs(); return;
        }
        if (photoLoading) return;
        photoLoading = true;
        const b = map.getBounds();
        const url = `photo_heatmap.php?ajax=photos&n=${b.getNorth()}&s=${b.getSouth()}&e=${b.getEast()}&w=${b.getWest()}`;
        fetch(url)
            .then(r => r.json())
            .then(data => { loadedPhotos = data.photos || []; renderThumbs(); })
            .catch(() => {})
            .finally(() => { photoLoading = false; });
    }
    map.on('moveend zoomend', loadThumbs);
    map.on('overlayadd', e => { if (e.layer === photoMarkers) loadThumbs(); });
    map.on('overlayremove', e => { if (e.layer === photoMarkers) renderThumbs(); });
});
</script>

</div><?php require __DIR__ . '/includes/layout_footer.php'; ?>
