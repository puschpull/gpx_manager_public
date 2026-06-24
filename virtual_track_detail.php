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

    <div style="font-size:13px; color:var(--text-muted); margin-bottom:8px;">
        🧭 Odhad trasy po cestách:
        <select id="routeProfile" style="padding:3px 6px; border:1px solid var(--border-color); border-radius:6px; background:var(--card-bg); color:var(--text-color); font-size:13px;">
            <option value="foot_hiking">turistická (preferuje značené stezky)</option>
            <option value="foot_fast">rychlá (nejkratší cesta)</option>
        </select>
        <span style="opacity:.7;">— zapni vrstvu „🧭 Odhad trasy po cestách" v ovladači mapy</span>
    </div>

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

    const overlayLayers = {
        "✏️ Spojnice fotek (čárkovaná)": photoLine,
        "🧭 Odhad trasy po cestách":     routeLayer,
        "📸 Moje fotografie":            photoThumbs,
        "📍 Polohy fotek na trase":      photoDots,
    };
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
    const PROFILE_LABEL = { foot_hiking: 'turistická', foot_fast: 'rychlá' };
    let routeLoading = false;
    function loadRoute() {
        if (routeLoading) return;
        routeLoading = true;
        routeLayer.clearLayers();
        const profile = (document.getElementById('routeProfile') || {}).value || 'foot_hiking';
        const hint = L.popup({ closeButton: false, autoClose: true })
            .setLatLng(map.getCenter()).setContent('⏳ Počítám trasu po cestách…').openOn(map);
        fetch('api/virtual_tracks/route.php?id=' + window.gpxVtData.vtId + '&profile=' + encodeURIComponent(profile))
            .then(r => r.json())
            .then(d => {
                map.closePopup(hint);
                if (d.ok && Array.isArray(d.coords) && d.coords.length > 1) {
                    L.polyline(d.coords, { color: '#1565c0', weight: 4, opacity: 0.85 }).addTo(routeLayer);
                    if (d.length_m != null) {
                        const km = (d.length_m / 1000).toFixed(1).replace('.', ',');
                        const lbl = PROFILE_LABEL[d.profile] || d.profile;
                        L.popup({ closeButton: true })
                            .setLatLng(d.coords[Math.floor(d.coords.length / 2)])
                            .setContent(`🧭 Odhad (${lbl}): ≈ ${km} km`).openOn(map);
                    }
                } else {
                    map.removeLayer(routeLayer);
                    alert('Odhad trasy se nepodařil: ' + (d.error || '?'));
                }
            })
            .catch(err => {
                map.closePopup(hint);
                map.removeLayer(routeLayer);
                alert('Chyba při výpočtu trasy: ' + err.message);
            })
            .finally(() => { routeLoading = false; });
    }
    const routeProfileSel = document.getElementById('routeProfile');
    if (routeProfileSel) routeProfileSel.addEventListener('change', () => {
        if (map.hasLayer(routeLayer)) loadRoute();
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
    let line = null;   // polyline trasy (hoisted kvůli živé změně barvy)

    fetch('api/virtual_tracks/points.php?id=' + window.gpxVtData.vtId)
        .then(r => r.json())
        .then(data => {
            const photos = data.photos || [];
            if (!photos.length) return;

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
    }
});
</script>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
