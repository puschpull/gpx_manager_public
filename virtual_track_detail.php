<?php
/**
 * Detail virtuální trasy — mapa (polyline z bodů fotek) + galerie.
 */
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
check_page_access('virtual_tracks');

init_theme_cookie();
$theme = active_theme();
$available_themes = available_themes();
$allowedLangs = available_langs();

$_isAdmin = !empty($_SESSION['is_admin']);

$vtId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM virtual_tracks WHERE id = ? LIMIT 1");
$stmt->execute([$vtId]);
$vt = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vt) {
    $page_title = 'Virtuální trasa nenalezena';
    require __DIR__ . '/includes/layout_header.php';
    echo '<div class="mx-auto max-w-7xl px-4 sm:px-6 pt-10"><p>Virtuální trasa nenalezena. <a href="virtual_tracks.php">← Zpět</a></p></div>';
    require __DIR__ . '/includes/layout_footer.php';
    exit;
}

$page_title = $vt['name'] ?: ('Virtuální trasa #' . $vt['id']);
require __DIR__ . '/includes/layout_header.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet.fullscreen@1.6.0/Control.FullScreen.css">
<link rel="stylesheet" href="css/style.css">
<script src="js/lib/format-utils.js" defer></script>
<script src="js/lightbox.js" defer></script>
<style>
    #map { height: 60vh; min-height: 380px; border-radius: 10px; }
    .vt-meta { display:flex; flex-wrap:wrap; gap:16px; font-size:13px; color:var(--text-muted); margin:8px 0 16px; }
    .vt-meta b { color: var(--text-color); }
    .vt-gallery { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:8px; margin-top:16px; }
    .vt-gallery img { width:100%; aspect-ratio:1/1; object-fit:cover; border-radius:6px; cursor:pointer; }
    .photo-pin { width:28px; height:28px; border-radius:50% 50% 50% 0; transform:rotate(-45deg); border:2px solid #fff; box-shadow:0 1px 4px rgba(0,0,0,.4); background-size:cover; background-position:center; }
    .vt-btn { padding: 6px 12px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-button); color: var(--text-color); cursor: pointer; font-size: 13px; }
    .vt-btn-primary { background: var(--accent-color); color: #fff; border-color: var(--accent-color); }
    #vtSplitBox input[type="number"] { width: 64px; padding: 4px 6px; border: 1px solid var(--border-color); border-radius: 6px; background: var(--card-bg); color: var(--text-color); }
    #vtSplitBox label { display: inline-flex; align-items: center; gap: 6px; }
    .split-seg-sw { display:inline-block; width:12px; height:12px; border-radius:3px; flex-shrink:0; }
    #vtCompareBox summary { color: var(--text-muted); cursor: pointer; user-select: none; }
    #vtCompareBox label { display: inline-flex; align-items: center; gap: 5px; cursor: pointer; }
    #vtSectionBox select { padding:3px 6px; border:1px solid var(--border-color); border-radius:6px; background:var(--card-bg); color:var(--text-color); font-size:12px; }
    #vtSectionBox .sec-row { padding:4px 0; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .sec-flag { font-size:18px; line-height:18px; cursor:pointer; filter: drop-shadow(0 1px 2px rgba(0,0,0,.5)); }
</style>

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <a href="virtual_tracks.php" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
        <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i> Zpět na virtuální trasy
    </a>
    <h1 class="font-[Manrope] text-2xl md:text-3xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
        <i data-lucide="route" class="w-7 h-7 text-terracotta-500" aria-hidden="true"></i>
        <span id="vtName"><?= h($vt['name'] ?: ('Virtuální trasa #' . $vt['id'])) ?></span>
        <?php if ($_isAdmin): ?>
        <button id="renameBtn" title="Přejmenovat" style="background:none;border:none;cursor:pointer;font-size:16px;">✏️</button>
        <?php endif; ?>
    </h1>
</section>

<div class="mx-auto max-w-7xl px-4 sm:px-6 mt-4 pb-12">
    <div class="vt-meta">
        <span>📅 <b><?= $vt['date_start'] ? h(date('j. n. Y', strtotime($vt['date_start']))) : '—' ?></b></span>
        <span>📸 <b><?= (int)$vt['photo_count'] ?></b> fotek</span>
        <span>📏 ≈<b id="vtDist"><?= h(number_format((float)$vt['distance_km'], 2, ',', ' ')) ?></b> km <em style="opacity:.7;">(vzdušná čára mezi fotkami)</em></span>
        <?php if ($vt['ascent'] !== null): ?><span>↑ <b><?= (int)$vt['ascent'] ?> m</b> · ↓ <b><?= (int)$vt['descent'] ?> m</b> <em style="opacity:.7;">(z výšky fotek, přibližné)</em></span><?php endif; ?>
    </div>
    <?php if ($_isAdmin): ?>
    <p style="font-size:12px; color:var(--text-muted); margin:-8px 0 12px;">💡 Tip: fotku na mapě můžeš myší <strong>přetáhnout</strong> na správné místo (oprava chybné GPS polohy) — poloha se uloží automaticky.</p>
    <?php endif; ?>

    <?php
    // Počasí dne výletu (znovupoužití widgetu z detailu trasy)
    $_vtHasWeather = !empty($vt['date_start']) && $vt['centroid_lat'] !== null && $vt['centroid_lon'] !== null;
    ?>
    <?php if ($_vtHasWeather): ?>
    <div id="weather-section" class="hidden mb-4">
        <div class="card-outdoor p-3 w-52">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60 mb-2 pb-2 border-b border-sand-200 dark:border-forest-700">
                <svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>
                Počasí
                <span class="ml-auto normal-case opacity-60" title="Data z klimatického modelu ERA5 (~10 km rozlišení). Mohou se lišit od skutečnosti.">~ model</span>
            </div>
            <div id="weather-widget">
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

    <?php if ($_isAdmin): ?>
    <!-- Editace metadat (admin) -->
    <div class="card-outdoor p-3 mb-4" style="display:flex; flex-wrap:wrap; gap:14px; align-items:flex-start;">
        <label style="display:flex; align-items:center; gap:6px; font-size:13px; cursor:pointer;">
            <input type="checkbox" id="vtFav" <?= !empty($vt['is_favorite']) ? 'checked' : '' ?>> ⭐ Oblíbená
        </label>
        <label style="display:flex; align-items:center; gap:6px; font-size:13px;">
            🎨 Barva trasy:
            <input type="color" id="vtColor" value="<?= h($vt['color'] ?: '#e91e63') ?>" style="width:40px; height:26px; padding:0; border:1px solid var(--border-color); border-radius:4px; cursor:pointer;">
            <button id="vtColorReset" class="vt-btn" style="padding:3px 8px; font-size:12px;" title="Výchozí barva">↺</button>
        </label>
        <label style="display:flex; flex-direction:column; gap:4px; font-size:13px; flex:1; min-width:220px;">
            📝 Poznámka:
            <textarea id="vtNote" rows="2" style="width:100%; padding:6px; border:1px solid var(--border-color); border-radius:6px; background:var(--card-bg); color:var(--text-color); resize:vertical;"><?= h($vt['note'] ?? '') ?></textarea>
        </label>
        <button id="vtSave" class="vt-btn vt-btn-primary" style="align-self:center;">💾 Uložit</button>
        <span id="vtSaveMsg" style="font-size:12px; color:var(--text-muted); align-self:center;"></span>
    </div>
    <?php elseif (!empty($vt['note'])): ?>
    <div class="card-outdoor p-3 mb-4" style="font-size:13px;">📝 <?= nl2br(h($vt['note'])) ?></div>
    <?php endif; ?>

    <?php if ($_isAdmin): ?>
    <!-- Rozdělení trasy na úseky (re-tuning naživo) -->
    <details class="card-outdoor p-3 mb-4" id="vtSplitBox">
        <summary style="cursor:pointer; user-select:none; font-weight:600; font-size:14px;">
            ✂️ Rozdělit trasu na úseky
            <span style="font-weight:400; color:var(--text-muted);">— dolaď parametry shlukování naživo (nic se neuloží, dokud nedáš „Rozdělit")</span>
        </summary>
        <div style="margin-top:12px; display:flex; flex-wrap:wrap; gap:14px; align-items:center; font-size:13px;">
            <label title="Nový úsek, když je mezi po sobě jdoucími fotkami pauza delší než tolik hodin.">
                Časová mezera (h): <input type="number" id="splitGapHours" value="4" min="0.5" step="0.5">
            </label>
            <label title="Nový úsek, když je skok v poloze mezi fotkami větší než tolik km.">
                Skok v poloze (km): <input type="number" id="splitGapKm" value="5" min="0.5" step="0.5">
            </label>
            <label title="Úseky s méně fotkami se přilepí k časově nejbližšímu sousednímu úseku (žádná fotka se neztratí).">
                Min. fotek na úsek: <input type="number" id="splitMinPhotos" value="3" min="2" step="1">
            </label>
            <button class="vt-btn" id="splitPreviewBtn">👁 Náhled</button>
            <button class="vt-btn vt-btn-primary" id="splitApplyBtn" style="display:none;">✂️ Rozdělit</button>
        </div>
        <div id="splitResult" style="margin-top:12px;"></div>
    </details>
    <?php endif; ?>

    <?php if ($_isAdmin): ?>
    <div style="font-size:13px; color:var(--text-muted); margin-bottom:8px;">
        🧭 Odhad trasy po cestách:
        <select id="routeProfile" style="padding:3px 6px; border:1px solid var(--border-color); border-radius:6px; background:var(--card-bg); color:var(--text-color); font-size:13px;">
            <optgroup label="Pěšky">
                <option value="foot_hiking">🥾 turistická (značené stezky)</option>
                <option value="foot_fast">🚶 pěšky nejkratší (chodník/pěšina)</option>
            </optgroup>
            <optgroup label="Kolo">
                <option value="bike_road">🚲 cyklostezka (asfalt)</option>
                <option value="bike_mountain">🚵 terénní cyklotrasa</option>
            </optgroup>
            <optgroup label="Auto">
                <option value="car_fast">🚗 silnice (rychlá)</option>
                <option value="car_short">🚗 silnice (nejkratší)</option>
            </optgroup>
        </select>
        <strong id="routeReadout" style="font-weight:600; color:var(--text-color);"></strong>
        <span style="opacity:.6;">— přepni profil, trasa se hned překreslí</span>
    </div>

    <details id="vtCompareBox" style="font-size:13px; margin:-2px 0 10px;">
        <summary>🔬 Porovnat varianty na mapě <span style="opacity:.7;">— vykreslí zaškrtnuté profily naráz různými barvami</span></summary>
        <div style="margin-top:8px;">
            <div style="display:flex; flex-wrap:wrap; gap:8px 16px; margin-bottom:8px;">
                <label><input type="checkbox" class="cmp-prof" value="foot_hiking" checked> 🥾 turistická</label>
                <label><input type="checkbox" class="cmp-prof" value="foot_fast" checked> 🚶 pěšky nejkratší</label>
                <label><input type="checkbox" class="cmp-prof" value="bike_road"> 🚲 cyklostezka</label>
                <label><input type="checkbox" class="cmp-prof" value="bike_mountain"> 🚵 terénní cyklo</label>
                <label><input type="checkbox" class="cmp-prof" value="car_fast"> 🚗 silnice (rychlá)</label>
                <label><input type="checkbox" class="cmp-prof" value="car_short"> 🚗 silnice (nejkratší)</label>
            </div>
            <button class="vt-btn" id="compareBtn">🔬 Porovnat zaškrtnuté</button>
            <button class="vt-btn" id="compareClearBtn" style="display:none;">✕ Skrýt porovnání</button>
            <div id="compareLegend" style="margin-top:10px;"></div>
        </div>
    </details>
    <?php endif; ?>

    <?php if ($_isAdmin): ?>
    <div id="vtSectionBox" class="card-outdoor p-3 mb-4" style="font-size:13px;">
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <strong>🧭✂️ Trasa po úsecích</strong>
            <span style="opacity:.7;">— rozděl trasu na úseky a každému dej jiný způsob pohybu (pěšky / kolo / silnice)</span>
        </div>
        <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <button class="vt-btn" id="secEditToggle">✏️ Zadat úseky klikáním</button>
            <button class="vt-btn vt-btn-primary" id="secCompute" style="display:none;">🧭 Spočítat po úsecích</button>
            <button class="vt-btn" id="secReset" style="display:none;">↺ Vyčistit</button>
            <button class="vt-btn" id="secHide" style="display:none;">✕ Skrýt</button>
        </div>
        <div id="secHint" style="margin-top:8px; opacity:.75;"></div>
        <div id="secList" style="margin-top:10px;"></div>
        <div id="secTotal" style="margin-top:8px; font-weight:600;"></div>
    </div>
    <?php endif; ?>

    <div id="map" role="img" aria-label="Mapa virtuální trasy"></div>
    <div id="vt-gallery" class="vt-gallery"></div>
</div>

<script defer src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="anonymous"></script>
<script defer src="https://unpkg.com/leaflet.vectorgrid@1.3.0/dist/Leaflet.VectorGrid.bundled.js" crossorigin="anonymous"></script>
<script defer src="https://unpkg.com/leaflet.fullscreen@1.6.0/Control.FullScreen.js" crossorigin="anonymous"></script>
<script defer src="js/lib/map-factory.js"></script>
<script src="js/detail-weather.js" defer></script>

<script>
window.gpxVtData = {
    apiKeys: {
        tf:        <?= js_safe_json(TF_API_KEY) ?>,
        mapycom:   <?= js_safe_json(MAPYCOM_API_KEY) ?>,
        mapillary: <?= js_safe_json(MAPILLARY_TOKEN) ?>
    },
    vtId: <?= (int)$vt['id'] ?>,
    csrf: <?= json_encode(csrf_token()) ?>,
    isAdmin: <?= $_isAdmin ? 'true' : 'false' ?>,
    color: <?= js_safe_json($vt['color'] ?: '') ?>
};

// Pro widget počasí (detail-weather.js čte window.gpxDetailData)
window.gpxDetailData = {
    dateStart:  <?= js_safe_json($vt['date_start'] ?? null) ?>,
    weatherLat: <?= js_safe_json($vt['centroid_lat'] !== null ? round((float)$vt['centroid_lat'], 5) : null) ?>,
    weatherLon: <?= js_safe_json($vt['centroid_lon'] !== null ? round((float)$vt['centroid_lon'], 5) : null) ?>
};

document.addEventListener('DOMContentLoaded', () => {
    const map = L.map('map', { fullscreenControl: true }).setView([50.0, 14.5], 8);
    const keys = window.gpxVtData.apiKeys;

    // Stejná sada vrstev jako na detailu normální trasy (sdílená factory)
    const { baseLayers, overlayLayers: overlayBase, baseOSM } = window.GpxMapFactory.createBaseLayers(keys, map);
    const overlayMapillary = window.GpxMapFactory.createMapillaryOverlay(keys.mapillary || "");
    const wikimediaMarkers = window.GpxMapFactory.createWikimediaLayer(map);

    // Vrstvy fotek — obě vypínatelné:
    //  - náhledy v "kapce" (mikronáhled)
    //  - červené tečky poloh fotek
    const photoThumbs = L.layerGroup();
    const photoDots   = L.layerGroup();
    const photoLine   = L.layerGroup();   // čárkovaná spojnice fotek (rovná čára)
    const routeLayer  = L.layerGroup();   // odhad trasy po cestách (Mapy.com)
    const splitPreview = L.layerGroup();  // transientní náhled rozdělení (re-tuning, mimo ovladač vrstev)
    const compareLayer = L.layerGroup();  // porovnání variant trasy (víc profilů naráz, mimo ovladač vrstev)
    const sectionEditLayer  = L.layerGroup();  // zadávání úseků: vodicí linie + značky zlomů
    const sectionRouteLayer = L.layerGroup();  // spočítaná trasa po úsecích (obarvená dle úseku)

    const overlayLayers = {
        "✏️ Spojnice fotek (čárkovaná)": photoLine,
        "📸 Moje fotografie":            photoThumbs,
        "📍 Polohy fotek na trase":      photoDots,
    };
    // Odhad trasy po cestách (přepínač profilu / porovnání / úseky) je nástroj jen pro admina
    if (window.gpxVtData.isAdmin) overlayLayers["🧭 Odhad trasy po cestách"] = routeLayer;
    Object.assign(overlayLayers, overlayBase);  // 🥾 Waymarked
    if (overlayMapillary) overlayLayers["📷 Fotografie (Mapillary)"] = overlayMapillary;
    overlayLayers["🖼️ Fotografie (Wikimedia)"] = wikimediaMarkers;

    // Obnovit uloženou základní vrstvu
    const savedLayer = localStorage.getItem("gpx_map_layer");
    if (savedLayer && baseLayers[savedLayer]) {
        Object.values(baseLayers).forEach(l => map.removeLayer(l));
        baseLayers[savedLayer].addTo(map);
    }
    map.on("baselayerchange", e => localStorage.setItem("gpx_map_layer", e.name));

    // === Odhad trasy po cestách (Mapy.com routing) — profil přepínatelný ===
    const PROFILE_LABEL = {
        foot_hiking:   '🥾 turistická',
        foot_fast:     '🚶 pěšky nejkratší',
        bike_road:     '🚲 cyklostezka',
        bike_mountain: '🚵 terénní cyklo',
        car_fast:      '🚗 silnice (rychlá)',
        car_short:     '🚗 silnice (nejkratší)',
    };
    const routeReadout = document.getElementById('routeReadout');
    function fmtDuration(sec) {
        sec = Math.round(sec);
        const h = Math.floor(sec / 3600), m = Math.round((sec % 3600) / 60);
        return h > 0 ? `${h} h ${m} min` : `${m} min`;
    }
    let routeLoading = false;
    function loadRoute() {
        if (routeLoading) return;
        routeLoading = true;
        routeLayer.clearLayers();
        const profile = (document.getElementById('routeProfile') || {}).value || 'foot_hiking';
        if (routeReadout) routeReadout.textContent = '⏳ počítám…';
        fetch('api/virtual_tracks/route.php?id=' + window.gpxVtData.vtId + '&profile=' + encodeURIComponent(profile))
            .then(r => r.json())
            .then(d => {
                if (d.ok && Array.isArray(d.coords) && d.coords.length > 1) {
                    L.polyline(d.coords, { color: '#1565c0', weight: 4, opacity: 0.85 }).addTo(routeLayer);
                    const lbl = PROFILE_LABEL[d.profile] || d.profile;
                    let txt = '';
                    if (d.length_m != null)   txt += '≈ ' + (d.length_m / 1000).toFixed(1).replace('.', ',') + ' km';
                    if (d.duration_s != null) txt += (txt ? ' · ' : '') + '~' + fmtDuration(d.duration_s);
                    if (routeReadout) routeReadout.textContent = txt ? `${lbl}: ${txt}` : lbl;
                } else {
                    map.removeLayer(routeLayer);
                    if (routeReadout) routeReadout.textContent = '';
                    alert('Odhad trasy se nepodařil: ' + (d.error || '?'));
                }
            })
            .catch(err => {
                map.removeLayer(routeLayer);
                if (routeReadout) routeReadout.textContent = '';
                alert('Chyba při výpočtu trasy: ' + err.message);
            })
            .finally(() => { routeLoading = false; });
    }
    const routeProfileSel = document.getElementById('routeProfile');
    if (routeProfileSel) {
        // obnovit dříve zvolený profil (přežije reload i rozdělení trasy)
        const savedProfile = localStorage.getItem('vt_route_profile');
        if (savedProfile && [...routeProfileSel.options].some(o => o.value === savedProfile)) {
            routeProfileSel.value = savedProfile;
        }
        routeProfileSel.addEventListener('change', () => {
            localStorage.setItem('vt_route_profile', routeProfileSel.value);
            // živý náhled: když vrstva běží, překresli; když ne, zapni ji (overlayadd → loadRoute)
            if (map.hasLayer(routeLayer)) loadRoute();
            else routeLayer.addTo(map);
        });
    }

    // === Porovnání variant trasy (víc profilů naráz, různé barvy) ===
    const CMP_COLORS = {
        foot_hiking:   '#2e7d32',
        foot_fast:     '#ef6c00',
        bike_road:     '#1565c0',
        bike_mountain: '#6a1b9a',
        car_fast:      '#c62828',
        car_short:     '#00838f',
    };
    const compareBtn      = document.getElementById('compareBtn');
    const compareClearBtn = document.getElementById('compareClearBtn');
    const compareLegend   = document.getElementById('compareLegend');

    function clearCompare() {
        compareLayer.clearLayers();
        if (map.hasLayer(compareLayer)) map.removeLayer(compareLayer);
        if (compareLegend) compareLegend.innerHTML = '';
        if (compareClearBtn) compareClearBtn.style.display = 'none';
    }
    if (compareClearBtn) compareClearBtn.addEventListener('click', clearCompare);

    if (compareBtn) compareBtn.addEventListener('click', () => {
        const profiles = [...document.querySelectorAll('.cmp-prof:checked')].map(c => c.value);
        if (!profiles.length) { if (compareLegend) compareLegend.textContent = 'Zaškrtni aspoň jednu variantu.'; return; }
        compareLayer.clearLayers();
        if (!map.hasLayer(compareLayer)) compareLayer.addTo(map);
        compareClearBtn.style.display = '';
        compareLegend.innerHTML = '⏳ počítám ' + profiles.length + ' variant…';

        Promise.all(profiles.map(profile =>
            fetch('api/virtual_tracks/route.php?id=' + window.gpxVtData.vtId + '&profile=' + encodeURIComponent(profile))
                .then(r => r.json())
                .then(d => ({ profile, d }))
                .catch(e => ({ profile, d: { ok: false, error: e.message } }))
        )).then(results => {
            let rows = '';
            const allBounds = [];
            results.forEach(({ profile, d }) => {
                const color = CMP_COLORS[profile] || '#555';
                const lbl   = PROFILE_LABEL[profile] || profile;
                if (d.ok && Array.isArray(d.coords) && d.coords.length > 1) {
                    const pl = L.polyline(d.coords, { color, weight: 4, opacity: 0.75 }).addTo(compareLayer);
                    allBounds.push(pl.getBounds());
                    let info = '';
                    if (d.length_m != null)   info += '≈ ' + (d.length_m / 1000).toFixed(1).replace('.', ',') + ' km';
                    if (d.duration_s != null) info += (info ? ' · ' : '') + '~' + fmtDuration(d.duration_s);
                    rows += `<div style="padding:2px 0; display:flex; align-items:center; gap:8px;">
                        <span class="split-seg-sw" style="background:${color};"></span>
                        <span>${lbl} — ${info || '?'}</span></div>`;
                } else {
                    rows += `<div style="padding:2px 0; display:flex; align-items:center; gap:8px; opacity:.6;">
                        <span class="split-seg-sw" style="background:${color}; opacity:.4;"></span>
                        <span>${lbl} — ⚠️ ${d.error || 'nepodařilo se'}</span></div>`;
                }
            });
            compareLegend.innerHTML = rows;
            if (allBounds.length) {
                let b = allBounds[0];
                for (let i = 1; i < allBounds.length; i++) b = b.extend(allBounds[i]);
                map.fitBounds(b, { padding: [30, 30] });
            }
        });
    });

    // === Ovladač vrstev ===
    L.control.layers(baseLayers, overlayLayers, { collapsed: true }).addTo(map);

    // === Obnova / výchozí stav overlay vrstev (vlastní klíč pro virtuální trasy) ===
    // První návštěva → zapnuté: čárkovaná spojnice + odhad trasy + náhledy fotek.
    const VT_OVL_KEY = "vt_map_overlays";
    const rawOvl = localStorage.getItem(VT_OVL_KEY);
    let activeOverlays;
    if (rawOvl === null) {
        activeOverlays = ["✏️ Spojnice fotek (čárkovaná)", "🧭 Odhad trasy po cestách", "📸 Moje fotografie"];
    } else {
        try { activeOverlays = JSON.parse(rawOvl) || []; } catch (e) { activeOverlays = []; }
    }
    activeOverlays.forEach(name => {
        if (!overlayLayers[name]) return;
        overlayLayers[name].addTo(map);
        if (name === "🧭 Odhad trasy po cestách") loadRoute();
        if (name === "🖼️ Fotografie (Wikimedia)" && wikimediaMarkers.loadPhotos) setTimeout(() => wikimediaMarkers.loadPhotos(), 800);
    });

    // === Ukládání stavu overlayů (po obnově, ať se obnova nezapisuje dvakrát) ===
    map.on("overlayadd", e => {
        const arr = JSON.parse(localStorage.getItem(VT_OVL_KEY) || "[]");
        if (!arr.includes(e.name)) { arr.push(e.name); localStorage.setItem(VT_OVL_KEY, JSON.stringify(arr)); }
        if (e.layer === routeLayer) loadRoute();
        if (e.name === "🖼️ Fotografie (Wikimedia)" && wikimediaMarkers.loadPhotos) wikimediaMarkers.loadPhotos();
    });
    map.on("overlayremove", e => {
        const arr = JSON.parse(localStorage.getItem(VT_OVL_KEY) || "[]");
        localStorage.setItem(VT_OVL_KEY, JSON.stringify(arr.filter(n => n !== e.name)));
    });

    const gallery = document.getElementById('vt-gallery');
    let line = null;       // polyline trasy (hoisted kvůli živé změně barvy)
    let vtPhotos = [];     // fotky trasy (hoisted kvůli náhledu rozdělení)

    fetch('api/virtual_tracks/points.php?id=' + window.gpxVtData.vtId)
        .then(r => r.json())
        .then(data => {
            const photos = data.photos || [];
            if (!photos.length) return;
            vtPhotos = photos;   // pro náhled rozdělení (obarvení úseků na mapě)
            document.dispatchEvent(new Event('vt:photosReady'));   // mód po úsecích čeká na data

            // Polyline v časovém pořadí (přibližná trasa mezi fotkami)
            const latlngs = photos.map(p => [p.lat, p.lon]);
            const lineColor = window.gpxVtData.color || '#e91e63';
            line = L.polyline(latlngs, { color: lineColor, weight: 3, opacity: 0.85, dashArray: '6,6' }).addTo(photoLine);
            map.fitBounds(line.getBounds(), { padding: [30, 30] });

            const lbPhotos = photos.map(p => ({ full_url: p.full_url, caption: p.caption, taken_at: p.taken_at, filename: p.filename }));
            const isAdmin = window.gpxVtData.isAdmin;
            const distEl = document.getElementById('vtDist');

            // Markery fotek: náhledová "kapka" (přetažitelná pro admina) + červená tečka.
            photos.forEach((p, idx) => {
                // náhledová kapka
                const icon = L.divIcon({ className: '', html: `<div class="photo-pin" style="background-image:url('${p.thumb_url}')"></div>`, iconSize: [28,28], iconAnchor: [14,28] });
                const marker = L.marker([p.lat, p.lon], { icon, draggable: isAdmin });
                marker.on('click', () => { if (window.gpxLightbox) window.gpxLightbox.open(lbPhotos, idx); });
                photoThumbs.addLayer(marker);

                // červená tečka polohy fotky
                const dot = L.circleMarker([p.lat, p.lon], { radius: 5, color: '#e91e63', fillColor: '#e91e63', fillOpacity: 0.85, weight: 1 });
                dot.on('click', () => { if (window.gpxLightbox) window.gpxLightbox.open(lbPhotos, idx); });
                photoDots.addLayer(dot);

                if (isAdmin) {
                    marker.on('dragend', () => {
                        const ll = marker.getLatLng();
                        photos[idx].lat = ll.lat;
                        photos[idx].lon = ll.lng;
                        dot.setLatLng(ll);                                  // sync tečky
                        line.setLatLngs(photos.map(q => [q.lat, q.lon]));   // překreslit trasu
                        const fd = new FormData();
                        fd.append('_csrf_token', window.gpxVtData.csrf);
                        fd.append('photo_id', p.id);
                        fd.append('lat', ll.lat);
                        fd.append('lon', ll.lng);
                        fetch('api/virtual_tracks/move_photo.php', { method: 'POST', body: fd })
                            .then(r => r.json())
                            .then(d => {
                                if (d.ok) {
                                    if (distEl && d.distance_km != null) distEl.textContent = String(d.distance_km).replace('.', ',');
                                } else {
                                    alert('Uložení polohy selhalo: ' + (d.error || '?'));
                                }
                            })
                            .catch(e => alert('Chyba: ' + e.message));
                    });
                }
            });

            // Galerie
            photos.forEach((p, idx) => {
                const img = document.createElement('img');
                img.src = p.thumb_url; img.loading = 'lazy'; img.alt = p.filename || '';
                img.addEventListener('click', () => { if (window.gpxLightbox) window.gpxLightbox.open(lbPhotos, idx); });
                gallery.appendChild(img);
            });
        })
        .catch(() => {});

    // Přejmenování (admin)
    if (window.gpxVtData.isAdmin) {
        const rb = document.getElementById('renameBtn');
        if (rb) rb.addEventListener('click', () => {
            const cur = document.getElementById('vtName').textContent;
            const name = prompt('Nový název virtuální trasy:', cur);
            if (!name || name.trim() === '' || name === cur) return;
            const fd = new FormData();
            fd.append('_csrf_token', window.gpxVtData.csrf);
            fd.append('id', window.gpxVtData.vtId);
            fd.append('name', name.trim());
            fetch('api/virtual_tracks/rename.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => { if (d.ok) document.getElementById('vtName').textContent = d.name; else alert('Chyba: ' + (d.error || '?')); })
                .catch(e => alert('Chyba: ' + e.message));
        });

        // Editace metadat: oblíbená / barva / poznámka
        const colorReset = document.getElementById('vtColorReset');
        if (colorReset) colorReset.addEventListener('click', () => {
            document.getElementById('vtColor').value = '#e91e63';
            if (line) line.setStyle({ color: '#e91e63' });
        });
        const colorInput = document.getElementById('vtColor');
        if (colorInput) colorInput.addEventListener('input', () => {
            if (line) line.setStyle({ color: colorInput.value });   // živý náhled
        });
        const saveBtn = document.getElementById('vtSave');
        if (saveBtn) saveBtn.addEventListener('click', () => {
            const msg = document.getElementById('vtSaveMsg');
            msg.textContent = '⏳ ukládám…';
            const fd = new FormData();
            fd.append('_csrf_token', window.gpxVtData.csrf);
            fd.append('id', window.gpxVtData.vtId);
            fd.append('is_favorite', document.getElementById('vtFav').checked ? '1' : '0');
            fd.append('color', document.getElementById('vtColor').value);
            fd.append('note', document.getElementById('vtNote').value);
            fetch('api/virtual_tracks/update.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => { msg.textContent = d.ok ? '✓ uloženo' : ('⚠️ ' + (d.error || 'chyba')); })
                .catch(e => { msg.textContent = '⚠️ ' + e.message; });
        });

        // === Rozdělení trasy na úseky (re-tuning naživo) ===
        const SPLIT_COLORS = ['#e6194B','#3cb44b','#4363d8','#f58231','#911eb4','#42d4f4','#f032e6','#9A6324','#469990','#bfef45','#800000','#000075','#808000','#fabed4'];
        const splitResult     = document.getElementById('splitResult');
        const splitApplyBtn   = document.getElementById('splitApplyBtn');
        const splitPreviewBtn = document.getElementById('splitPreviewBtn');
        let splitLastSegments = null;

        function splitParams() {
            const fd = new FormData();
            fd.append('_csrf_token', window.gpxVtData.csrf);
            fd.append('id', window.gpxVtData.vtId);
            fd.append('gap_hours', document.getElementById('splitGapHours').value);
            fd.append('gap_km', document.getElementById('splitGapKm').value);
            fd.append('min_photos', document.getElementById('splitMinPhotos').value);
            return fd;
        }

        function clearSplitPreview() {
            splitPreview.clearLayers();
            if (map.hasLayer(splitPreview)) map.removeLayer(splitPreview);
        }

        function drawSplitPreview(segments) {
            splitPreview.clearLayers();
            if (!segments || segments.length < 2) { clearSplitPreview(); return; }
            const byId = {};
            vtPhotos.forEach(p => { byId[p.id] = p; });
            segments.forEach((seg, si) => {
                const color = SPLIT_COLORS[si % SPLIT_COLORS.length];
                const latlngs = [];
                seg.photo_ids.forEach(pid => {
                    const p = byId[pid];
                    if (!p) return;
                    latlngs.push([p.lat, p.lon]);
                    L.circleMarker([p.lat, p.lon], { radius: 6, color: '#fff', weight: 1, fillColor: color, fillOpacity: 0.95 }).addTo(splitPreview);
                });
                if (latlngs.length > 1) L.polyline(latlngs, { color, weight: 4, opacity: 0.9 }).addTo(splitPreview);
            });
            if (!map.hasLayer(splitPreview)) splitPreview.addTo(map);
        }

        if (splitPreviewBtn) splitPreviewBtn.addEventListener('click', () => {
            splitResult.innerHTML = '⏳ Počítám…';
            splitApplyBtn.style.display = 'none';
            splitLastSegments = null;
            fetch('api/virtual_tracks/resplit_preview.php', { method: 'POST', body: splitParams() })
                .then(r => r.json())
                .then(d => {
                    if (!d.ok) { splitResult.innerHTML = '⚠️ ' + (d.error || 'Chyba'); return; }
                    if (!d.segment_count || d.segment_count <= 1) {
                        splitResult.innerHTML = `Trasa zůstává vcelku (${d.photo_total} fotek, 1 úsek) — pro rozdělení zkus menší časovou mezeru nebo menší skok v poloze.`;
                        clearSplitPreview();
                        return;
                    }
                    splitLastSegments = d.segments;
                    drawSplitPreview(d.segments);
                    let html = `<p style="margin:0 0 6px;"><strong>Rozdělilo by se na ${d.segment_count} úseků</strong> (${d.photo_total} fotek):</p>`;
                    d.segments.forEach((s, i) => {
                        const color  = SPLIT_COLORS[i % SPLIT_COLORS.length];
                        const dlabel = s.date_start ? new Date(s.date_start.replace(' ', 'T')).toLocaleDateString('cs-CZ') : '';
                        const km     = String(s.distance_km).replace('.', ',');
                        html += `<div style="padding:3px 0; display:flex; align-items:center; gap:8px;">
                            <span class="split-seg-sw" style="background:${color};"></span>
                            <span>Úsek ${i + 1} — 📸 ${s.photo_count} · 📏 ≈${km} km${s.ascent != null ? ` · ↑${s.ascent} m` : ''}${dlabel ? ` · 📅 ${dlabel}` : ''}</span>
                        </div>`;
                    });
                    html += `<p style="margin:8px 0 0; color:var(--text-muted); font-size:12px;">Náhled je obarvený na mapě (nic se zatím neuložilo). „Rozdělit" nechá původní trasu jako 1. úsek (název/poznámku/barvu/⭐ si nechá), ostatní vytvoří jako nové trasy.</p>`;
                    splitResult.innerHTML = html;
                    splitApplyBtn.style.display = '';
                })
                .catch(e => { splitResult.innerHTML = '⚠️ ' + e.message; });
        });

        if (splitApplyBtn) splitApplyBtn.addEventListener('click', () => {
            if (!splitLastSegments || splitLastSegments.length < 2) return;
            if (!confirm(`Rozdělit trasu na ${splitLastSegments.length} úseků? Původní trasa si nechá 1. úsek, zbytek vznikne jako nové virtuální trasy.`)) return;
            splitResult.innerHTML = '⏳ Rozděluji…';
            splitApplyBtn.style.display = 'none';
            fetch('api/virtual_tracks/resplit_apply.php', { method: 'POST', body: splitParams() })
                .then(r => r.json())
                .then(d => {
                    if (!d.ok) { splitResult.innerHTML = '⚠️ ' + (d.error || 'Chyba'); return; }
                    splitResult.innerHTML = '✅ ' + d.message + ' Stránka se obnoví…';
                    setTimeout(() => location.reload(), 1300);
                })
                .catch(e => { splitResult.innerHTML = '⚠️ ' + e.message; });
        });

        // === Mód po úsecích: rozděl trasu na úseky a každému dej vlastní profil ===
        const SECTION_COLORS = ['#e6194B','#3cb44b','#4363d8','#f58231','#911eb4','#42d4f4','#f032e6','#9A6324','#469990','#bfef45','#800000','#000075'];
        const secEditToggle = document.getElementById('secEditToggle');
        const secCompute    = document.getElementById('secCompute');
        const secReset      = document.getElementById('secReset');
        const secHide       = document.getElementById('secHide');
        const secHint       = document.getElementById('secHint');
        const secList       = document.getElementById('secList');
        const secTotal      = document.getElementById('secTotal');
        const SECTION_LS_KEY = 'vt_sections_' + window.gpxVtData.vtId;

        let secMode = false;               // režim zadávání hranic
        let secBoundaries = [];            // setříděné interní indexy zlomů
        const secProfileByStart = {};      // profil úseku podle jeho počátečního indexu
        let secMapClickBound = false;

        const secGlobalProfile = () => (document.getElementById('routeProfile') || {}).value || 'foot_hiking';
        const secStarts = () => [0, ...secBoundaries];   // počáteční indexy úseků
        function secRange(j, starts) {
            const from = starts[j];
            const to = (j + 1 < starts.length) ? starts[j + 1] : (vtPhotos.length - 1);
            return { from, to };
        }
        function secProfileOptions(sel) {
            return Object.keys(PROFILE_LABEL).map(v =>
                `<option value="${v}"${v === sel ? ' selected' : ''}>${PROFILE_LABEL[v]}</option>`).join('');
        }

        function saveSections() {
            const starts = secStarts();
            const profiles = starts.map(s => secProfileByStart[s] || secGlobalProfile());
            localStorage.setItem(SECTION_LS_KEY, JSON.stringify({ boundaries: secBoundaries, starts, profiles }));
        }
        function loadSections() {
            try {
                const raw = JSON.parse(localStorage.getItem(SECTION_LS_KEY) || 'null');
                if (raw && Array.isArray(raw.boundaries)) {
                    secBoundaries = raw.boundaries.slice().sort((a, b) => a - b);
                    if (Array.isArray(raw.starts) && Array.isArray(raw.profiles)) {
                        raw.starts.forEach((s, i) => { if (raw.profiles[i]) secProfileByStart[s] = raw.profiles[i]; });
                    }
                    return true;
                }
            } catch (e) {}
            return false;
        }

        function nearestPhotoIndex(latlng) {
            let best = -1, bestD = Infinity;
            vtPhotos.forEach((p, i) => {
                const d = map.distance(latlng, L.latLng(p.lat, p.lon));
                if (d < bestD) { bestD = d; best = i; }
            });
            return best;
        }

        function renderSectionEdit() {
            sectionEditLayer.clearLayers();
            if (!vtPhotos.length) return;
            const guide = vtPhotos.map(p => [p.lat, p.lon]);
            L.polyline(guide, { color: '#888', weight: 2, opacity: 0.5, dashArray: '4,5' }).addTo(sectionEditLayer);
            // širší průhledný pruh podél trasy → snazší klikání zlomů (i mimo přesnou linku)
            L.polyline(guide, { color: '#000', weight: 16, opacity: 0, lineCap: 'round' })
                .on('click', secMapClick).addTo(sectionEditLayer);
            secBoundaries.forEach(idx => {
                const p = vtPhotos[idx];
                if (!p) return;
                const m = L.marker([p.lat, p.lon], {
                    icon: L.divIcon({ className: '', html: '<div class="sec-flag">🚩</div>', iconSize: [20, 20], iconAnchor: [3, 18] }),
                    title: 'Klikni pro odebrání zlomu'
                });
                m.on('click', () => { secBoundaries = secBoundaries.filter(b => b !== idx); afterBoundaryChange(); });
                sectionEditLayer.addLayer(m);
            });
        }

        function renderSectionList(results) {
            const starts = secStarts();
            let html = '';
            starts.forEach((s, j) => {
                const { from, to } = secRange(j, starts);
                const color = SECTION_COLORS[j % SECTION_COLORS.length];
                const prof  = secProfileByStart[s] || secGlobalProfile();
                let info = '';
                if (results && results[j]) {
                    const r = results[j];
                    if (r.ok) {
                        if (r.length_m != null)   info += '≈ ' + (r.length_m / 1000).toFixed(1).replace('.', ',') + ' km';
                        if (r.duration_s != null) info += (info ? ' · ' : '') + '~' + fmtDuration(r.duration_s);
                    } else {
                        info = '⚠️ ' + (r.error || 'chyba');
                    }
                }
                html += `<div class="sec-row">
                    <span class="split-seg-sw" style="background:${color};"></span>
                    <span>Úsek ${j + 1} <span style="opacity:.6;">(fotky ${from + 1}–${to + 1})</span>:</span>
                    <select data-start="${s}" class="sec-prof">${secProfileOptions(prof)}</select>
                    <span class="sec-info" style="opacity:.85;">${info}</span>
                </div>`;
            });
            secList.innerHTML = html;
            secList.querySelectorAll('.sec-prof').forEach(sel => {
                sel.addEventListener('change', () => { secProfileByStart[sel.dataset.start] = sel.value; saveSections(); });
            });
        }

        function afterBoundaryChange() {
            secBoundaries = [...new Set(secBoundaries)].sort((a, b) => a - b);
            renderSectionEdit();
            renderSectionList(null);
            secCompute.style.display = secBoundaries.length ? '' : 'none';
            secReset.style.display   = secBoundaries.length ? '' : 'none';
            saveSections();
        }

        function secMapClick(e) {
            if (!secMode || !vtPhotos.length) return;
            let idx = nearestPhotoIndex(e.latlng);
            if (idx < 0) return;
            idx = Math.max(1, Math.min(idx, vtPhotos.length - 2));   // ne na úplných koncích
            if (secBoundaries.includes(idx)) return;
            secBoundaries.push(idx);
            afterBoundaryChange();
        }

        function enterSecMode() {
            secMode = true;
            secEditToggle.textContent = '✓ Hotovo se zadáváním';
            secEditToggle.classList.add('vt-btn-primary');
            secHint.innerHTML = 'Klikni na mapu v místě, kde se měnil způsob pohybu — zlom se přichytí k nejbližší fotce. Klikni na 🚩 pro odebrání. Pak vyber profil každému úseku a dej <strong>Spočítat po úsecích</strong>.';
            if (!map.hasLayer(sectionEditLayer)) sectionEditLayer.addTo(map);
            renderSectionEdit();
            renderSectionList(null);
            if (!secMapClickBound) { map.on('click', secMapClick); secMapClickBound = true; }
        }
        function exitSecMode() {
            secMode = false;
            secEditToggle.textContent = '✏️ Zadat úseky klikáním';
            secEditToggle.classList.remove('vt-btn-primary');
            if (secMapClickBound) { map.off('click', secMapClick); secMapClickBound = false; }
        }

        if (secEditToggle) secEditToggle.addEventListener('click', () => {
            if (!vtPhotos.length) { secHint.textContent = 'Trasa se ještě načítá, zkus to za chvilku…'; return; }
            secMode ? exitSecMode() : enterSecMode();
        });

        if (secReset) secReset.addEventListener('click', () => {
            secBoundaries = [];
            Object.keys(secProfileByStart).forEach(k => delete secProfileByStart[k]);
            sectionRouteLayer.clearLayers();
            if (map.hasLayer(sectionRouteLayer)) map.removeLayer(sectionRouteLayer);
            secTotal.textContent = '';
            secHide.style.display = 'none';
            localStorage.removeItem(SECTION_LS_KEY);
            afterBoundaryChange();
        });

        if (secHide) secHide.addEventListener('click', () => {
            sectionRouteLayer.clearLayers();
            if (map.hasLayer(sectionRouteLayer)) map.removeLayer(sectionRouteLayer);
            secHide.style.display = 'none';
            secTotal.textContent = '';
        });

        if (secCompute) secCompute.addEventListener('click', () => {
            if (!secBoundaries.length) return;
            const starts = secStarts();
            exitSecMode();
            sectionRouteLayer.clearLayers();
            if (!map.hasLayer(sectionRouteLayer)) sectionRouteLayer.addTo(map);
            if (map.hasLayer(sectionEditLayer)) map.removeLayer(sectionEditLayer);
            secTotal.textContent = '⏳ počítám ' + starts.length + ' úseků…';
            secHide.style.display = '';

            const reqs = starts.map((s, j) => {
                const { from, to } = secRange(j, starts);
                const profile = secProfileByStart[s] || secGlobalProfile();
                return fetch(`api/virtual_tracks/route.php?id=${window.gpxVtData.vtId}&from=${from}&to=${to}&profile=${encodeURIComponent(profile)}`)
                    .then(r => r.json()).then(d => ({ j, d })).catch(e => ({ j, d: { ok: false, error: e.message } }));
            });

            Promise.all(reqs).then(arr => {
                const results = [];
                let totLen = 0, totDur = 0, anyDur = false;
                const allBounds = [];
                arr.sort((a, b) => a.j - b.j).forEach(({ j, d }) => {
                    results[j] = d;
                    const color = SECTION_COLORS[j % SECTION_COLORS.length];
                    if (d.ok && Array.isArray(d.coords) && d.coords.length > 1) {
                        const pl = L.polyline(d.coords, { color, weight: 5, opacity: 0.85 }).addTo(sectionRouteLayer);
                        allBounds.push(pl.getBounds());
                        if (d.length_m != null) totLen += d.length_m;
                        if (d.duration_s != null) { totDur += d.duration_s; anyDur = true; }
                    }
                });
                renderSectionList(results);
                let t = `Celkem: ≈ ${(totLen / 1000).toFixed(1).replace('.', ',')} km`;
                if (anyDur) t += ` · ~${fmtDuration(totDur)}`;
                secTotal.textContent = t;
                if (allBounds.length) {
                    let b = allBounds[0];
                    for (let i = 1; i < allBounds.length; i++) b = b.extend(allBounds[i]);
                    map.fitBounds(b, { padding: [30, 30] });
                }
                saveSections();
            });
        });

        // obnovit uložené úseky (definici) — až budou fotky načtené (kvůli rozsahům)
        function secRestore() {
            if (loadSections() && secBoundaries.length) {
                renderSectionList(null);
                secCompute.style.display = '';
                secReset.style.display   = '';
                secHint.innerHTML = 'Načteny uložené úseky. Klikni <strong>Spočítat po úsecích</strong>, nebo <strong>Zadat úseky klikáním</strong> pro úpravu.';
            }
        }
        if (vtPhotos.length) secRestore();
        else document.addEventListener('vt:photosReady', secRestore, { once: true });
    }
});
</script>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
