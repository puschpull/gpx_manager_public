<?php
/**
 * Vyhledávání tras podle mapy — nakresli obdélník, najdi trasy
 */
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
check_page_access('map_search');

$allowedLangs = available_langs();

/* ===== AJAX endpoint — hledání tras v obdélníku ===== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search') {
    header('Content-Type: application/json; charset=utf-8');

    $minLat = (float)($_GET['minlat'] ?? 0);
    $maxLat = (float)($_GET['maxlat'] ?? 0);
    $minLon = (float)($_GET['minlon'] ?? 0);
    $maxLon = (float)($_GET['maxlon'] ?? 0);

    if ($minLat == 0 && $maxLat == 0) {
        echo json_encode(['tracks' => []]);
        exit;
    }

    // Hledáme trasy jejichž bounding box se překrývá s vybraným obdélníkem
    $stmt = $pdo->query("
        SELECT id, filename, track_name, distance_km, ascent, descent,
               date_start, duration, bounds, color, difficulty, is_favorite
        FROM tracks
        WHERE bounds IS NOT NULL AND bounds != ''
    ");
    $allTracks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($allTracks as $t) {
        $b = json_decode($t['bounds'], true);
        if (!$b || !isset($b['minlat'], $b['maxlat'], $b['minlon'], $b['maxlon'])) continue;

        // Překrytí obdélníků: trasa protíná vybraný výřez
        $overlaps = !(
            $b['maxlat'] < $minLat ||
            $b['minlat'] > $maxLat ||
            $b['maxlon'] < $minLon ||
            $b['minlon'] > $maxLon
        );

        if ($overlaps) {
            $results[] = [
                'id'          => (int)$t['id'],
                'filename'    => $t['filename'],
                'track_name'  => $t['track_name'],
                'distance_km' => round((float)$t['distance_km'], 2),
                'ascent'      => round((float)$t['ascent'], 0),
                'descent'     => round((float)$t['descent'], 0),
                'date_start'  => $t['date_start'],
                'duration'    => (int)$t['duration'],
                'color'       => $t['color'],
                'difficulty'  => $t['difficulty'] !== null ? (int)$t['difficulty'] : null,
                'is_favorite' => (int)$t['is_favorite'],
                'bounds'      => $b,
            ];
        }
    }

    // Seřadit podle data
    usort($results, fn($a, $b) => ($b['date_start'] ?? '') <=> ($a['date_start'] ?? ''));

    echo json_encode(['tracks' => $results, 'count' => count($results)], JSON_UNESCAPED_UNICODE);
    exit;
}
?>

<?php
$page_title = t('h1_map_search');
require __DIR__ . '/includes/layout_header.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H" crossorigin="anonymous">
<link rel="stylesheet" href="https://unpkg.com/leaflet.fullscreen@1.6.0/Control.FullScreen.css" integrity="sha384-weDCJ80JNrg6W2Dha8CBrQyz5PZVPOZ39Lw7vWOzm65zqKvZZfSq/3rR77RY5TWm" crossorigin="anonymous">
    <style>
        #map { height: calc(100vh - 340px) !important; min-height: 350px; }
        .search-status {
            font-size: 14px;
            color: var(--text-muted);
            margin: 8px 0;
            padding: 8px 12px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
        }
        .search-results { margin-top: 10px; }
        .search-results table { width: 100%; font-size: 13px; border-collapse: collapse; }
        .search-results th { position: static; background: var(--table-header-bg); padding: 6px 8px; text-align: left; border-bottom: 1px solid var(--border-color); }
        .search-results td { padding: 5px 8px; border-bottom: 1px solid var(--border-color); }
        .search-results tr:hover td { background: var(--table-row-hover); }
        .table-responsive { width: 100%; overflow-x: auto; overflow-y: visible; -webkit-overflow-scrolling: touch; border-radius: 6px; }
        .bbox-preview {
            display: inline-block;
            width: 12px; height: 12px;
            border: 2px solid;
            margin-right: 4px;
            vertical-align: middle;
        }
        .result-btn {
            display: inline-flex; align-items: center;
            padding: 2px 8px; border-radius: 5px; font-size: 12px;
            background: var(--bg-secondary); border: 1px solid var(--border-color);
            color: var(--text-color); text-decoration: none;
            transition: background .15s;
        }
        .result-btn:hover { background: var(--accent-color); color: #fff; }
    </style>

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <a href="index.php" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
        <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i>
        <?= htmlspecialchars(t('back_to_list')) ?>
    </a>
    <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
        <i data-lucide="search" class="w-8 h-8 text-terracotta-500" aria-hidden="true"></i>
        <?= htmlspecialchars(t('h1_map_search')) ?>
    </h1>
</section>

<div class="mx-auto max-w-7xl px-4 sm:px-6 mt-6 pb-12">


<div class="mb-3 flex flex-wrap gap-2">
    <button id="clearBtn" type="button" class="btn-outdoor btn-outdoor-ghost">
        <i data-lucide="x" class="w-4 h-4" aria-hidden="true"></i>
        Vymazat výběr
    </button>
</div>
<div id="search-status" class="search-status">
    Nakreslete obdélník na mapě: klikněte na první roh a potom na protější roh.
</div>

<!-- A11Y-021: skip link + role="img" + aria-label -->
<a href="#search-results" class="sr-only-focusable">
    <?= htmlspecialchars(t('skip_to_data', 'Přejít na data trasy')) ?>
</a>
<div id="map"
     role="img"
     aria-label="<?= htmlspecialchars(t('map_aria_generic', 'Interaktivní mapa')) ?>"></div>

<div id="search-results" class="search-results" style="display:none;">
    <div class="table-responsive" style="max-height:400px;">
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Název trasy</th>
                <th>Vzdálenost (km)</th>
                <th>Stoupání (m)</th>
                <th>Obtížnost</th>
                <th>Datum</th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody id="results-tbody"></tbody>
    </table>
    </div>
</div>

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

<!-- Sdílená map factory (geolokační tlačítko) -->
<script src="<?= asset('js/lib/map-factory.js') ?>"></script>

<script>
window.gpxMapSearchData = {
    apiKeys: {
        tf:        <?= js_safe_json(TF_API_KEY) ?>,
        mapycom:   <?= js_safe_json(MAPYCOM_API_KEY) ?>,
        mapillary: <?= js_safe_json(MAPILLARY_TOKEN) ?>
    }
};

document.addEventListener('DOMContentLoaded', () => {
    const map = L.map('map', { fullscreenControl: true }).setView([50.0, 14.5], 8);

    // === Vrstvy via shared factory (js/lib/map-factory.js) — všechny podklady
    //     i překryvy (Waymarked ×3, stínování, POI…) na jednom místě ===
    const keys = window.gpxMapSearchData.apiKeys;
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

    let firstCorner = null;
    let rectangle = null;
    let bboxRects = []; // obdélníky nalezených tras
    const statusEl = document.getElementById('search-status');
    const resultsEl = document.getElementById('search-results');
    const tbodyEl = document.getElementById('results-tbody');

    const diffDots = (d) => {
        if (d === null || d === undefined) return '—';
        const colors = {1:'#4caf50', 2:'#8bc34a', 3:'#ff9800', 4:'#f44336', 5:'#9c27b0'};
        const dots = '●'.repeat(d) + '○'.repeat(5 - d);
        return `<span style="color:${colors[d] || '#999'}">${dots}</span>`;
    };

    const formatHMS = (s) => {
        if (!s) return '—';
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        return `${h}:${String(m).padStart(2, '0')}`;
    };

    function clearSelection() {
        if (rectangle) { map.removeLayer(rectangle); rectangle = null; }
        bboxRects.forEach(r => map.removeLayer(r));
        bboxRects = [];
        firstCorner = null;
        resultsEl.style.display = 'none';
        tbodyEl.innerHTML = '';
        statusEl.textContent = 'Nakreslete obdélník na mapě: klikněte na první roh a potom na protější roh.';
    }

    document.getElementById('clearBtn')?.addEventListener('click', clearSelection);

    map.on('click', e => {
        if (!firstCorner) {
            // První klik — uloží roh
            firstCorner = e.latlng;
            statusEl.textContent = `První roh: ${e.latlng.lat.toFixed(5)}, ${e.latlng.lng.toFixed(5)} — klikněte na protější roh.`;

            // Vymazat předchozí
            if (rectangle) map.removeLayer(rectangle);
            bboxRects.forEach(r => map.removeLayer(r));
            bboxRects = [];
        } else {
            // Druhý klik — vytvoří obdélník
            const bounds = L.latLngBounds(firstCorner, e.latlng);
            if (rectangle) map.removeLayer(rectangle);
            rectangle = L.rectangle(bounds, {
                color: '#3388ff', weight: 3, fillOpacity: 0.15
            }).addTo(map);

            statusEl.textContent = 'Hledání tras v oblasti...';

            const sw = bounds.getSouthWest();
            const ne = bounds.getNorthEast();

            fetch(`map_search.php?ajax=search&minlat=${sw.lat}&maxlat=${ne.lat}&minlon=${sw.lng}&maxlon=${ne.lng}`)
                .then(r => r.json())
                .then(data => {
                    // Vymazat předchozí bbox obdélníky
                    bboxRects.forEach(r => map.removeLayer(r));
                    bboxRects = [];

                    statusEl.textContent = `Nalezeno ${data.count} tras v oblasti.`;

                    if (data.tracks.length === 0) {
                        resultsEl.style.display = 'none';
                        return;
                    }

                    // Zobrazit bounding boxy nalezených tras
                    const trackColors = ['#e74c3c','#3498db','#2ecc71','#f39c12','#9b59b6','#1abc9c','#e67e22','#34495e'];
                    data.tracks.forEach((t, i) => {
                        if (t.bounds) {
                            const col = trackColors[i % trackColors.length];
                            const rect = L.rectangle(
                                [[t.bounds.minlat, t.bounds.minlon], [t.bounds.maxlat, t.bounds.maxlon]],
                                { color: col, weight: 1, fillOpacity: 0.08, dashArray: '4 4' }
                            ).addTo(map);
                            rect.bindTooltip(t.track_name || t.filename, { sticky: true });
                            bboxRects.push(rect);
                        }
                    });

                    // Naplnit tabulku
                    tbodyEl.innerHTML = '';
                    data.tracks.forEach((t, i) => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td>${i + 1}</td>
                            <td><a href="detail.php?id=${t.id}">${t.track_name || t.filename}</a>
                                ${t.is_favorite ? ' ⭐' : ''}</td>
                            <td>${t.distance_km}</td>
                            <td>${t.ascent}</td>
                            <td>${diffDots(t.difficulty)}</td>
                            <td style="white-space:nowrap;">${(t.date_start || '—').substring(0, 10)}</td>
                            <td>
                                <a href="detail.php?id=${t.id}" class="result-btn">Mapa</a>
                                <a href="edit.php?id=${t.id}" class="result-btn">Edit</a>
                            </td>
                        `;
                        tbodyEl.appendChild(tr);
                    });
                    resultsEl.style.display = 'block';
                });

            firstCorner = null;
        }
    });
});
</script>

</div><?php require __DIR__ . '/includes/layout_footer.php'; ?>