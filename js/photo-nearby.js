/**
 * ===========================================================
 *  GPX Manager – Photo Nearby Module
 *  Kliknutím na mapu najde nejbližší fotografie z výletů
 *  (vzor: nearby-map.js; fotky na mapě: photo_heatmap)
 * ===========================================================
 */

if (window.GPX_DEBUG) console.log("📷 photo-nearby.js načten");

document.addEventListener("DOMContentLoaded", () => {

    const cfg  = window.gpxPhotoNearbyData || {};
    const i18n = cfg.i18n || {};
    const keys = cfg.apiKeys || {};

    // ===== Inicializace mapy =====
    const map = L.map("map", {
        center: [49.8, 15.5],
        zoom: 8,
        fullscreenControl: true
    });

    // ===== Základní vrstvy a Mapillary via shared factory (js/lib/map-factory.js) =====
    const { baseLayers, overlayLayers: _overlayBase, baseOSM } = window.GpxMapFactory.createBaseLayers(keys, map);
    const overlayMapillary = window.GpxMapFactory.createMapillaryOverlay(keys.mapillary || "");

    const wikimediaMarkers = window.GpxMapFactory.createWikimediaLayer(map);
    function loadWikimediaPhotos() { wikimediaMarkers.loadPhotos(); }

    const overlayLayers = Object.assign({}, _overlayBase,
        overlayMapillary ? { "📷 Fotografie (Mapillary)": overlayMapillary } : {},
        { "🖼️ Fotografie (Wikimedia)": wikimediaMarkers }
    );

    L.control.layers(baseLayers, overlayLayers, { collapsed: true }).addTo(map);
    if (window.GpxMapFactory.createLocateControl) window.GpxMapFactory.createLocateControl(map);

    // ===== Obnova uložené vrstvy (stejný mechanismus jako nearby/heatmapa) =====
    const savedLayer = localStorage.getItem("gpx_map_layer");
    if (savedLayer && baseLayers[savedLayer]) {
        map.removeLayer(baseOSM);
        baseLayers[savedLayer].addTo(map);
    }
    map.on("baselayerchange", e => localStorage.setItem("gpx_map_layer", e.name));

    // ===== Stav =====
    let clickMarker  = null;
    let radiusCircle = null;
    const photoLayer = L.layerGroup().addTo(map);
    let photos    = [];
    let isLoading = false;
    let lastPoint = null;

    const statusEl    = document.getElementById("pn-status");
    const gridEl      = document.getElementById("pn-grid");
    const resultsWrap = document.getElementById("pn-results");
    const radiusSel   = document.getElementById("pnRadius");
    const limitSel    = document.getElementById("pnLimit");

    const esc = (s) => window.GpxFmt.escHtml(s);

    function setStatus(text, cls) {
        if (!statusEl) return;
        statusEl.textContent = text;
        statusEl.className = "nearby-status" + (cls ? " " + cls : "");
    }

    function formatDist(m) {
        return m < 1000 ? Math.round(m) + " m" : (m / 1000).toFixed(2).replace(".", ",") + " km";
    }

    // ===== Osový kříž na kliknutém bodě (stejné SVG jako nearby) =====
    function placeCross(lat, lng) {
        if (clickMarker) {
            clickMarker.setLatLng([lat, lng]);
            return;
        }
        const size = 30, half = size / 2;
        const crossSvg = `
            <svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" xmlns="http://www.w3.org/2000/svg">
                <line x1="0" y1="${half}" x2="${size}" y2="${half}" stroke="#000" stroke-width="3"/>
                <line x1="0" y1="${half}" x2="${size}" y2="${half}" stroke="#ff0000" stroke-width="1.5"/>
                <line x1="${half}" y1="0" x2="${half}" y2="${size}" stroke="#000" stroke-width="3"/>
                <line x1="${half}" y1="0" x2="${half}" y2="${size}" stroke="#ff0000" stroke-width="1.5"/>
                <circle cx="${half}" cy="${half}" r="3" fill="#ff0000" stroke="#000" stroke-width="1.5"/>
            </svg>`;
        clickMarker = L.marker([lat, lng], {
            icon: L.divIcon({ className: '', html: crossSvg, iconSize: [size, size], iconAnchor: [half, half] }),
            zIndexOffset: 1000
        }).addTo(map);
    }

    // ===== Kruh zvoleného okruhu =====
    function placeCircle(lat, lng, radiusKm) {
        if (radiusCircle) map.removeLayer(radiusCircle);
        radiusCircle = L.circle([lat, lng], {
            radius: radiusKm * 1000,
            color: '#1565c0', weight: 1.5, dashArray: '6 6',
            fillColor: '#1565c0', fillOpacity: 0.05,
            interactive: false
        }).addTo(map);
    }

    // ===== Otevření lightboxu na indexu =====
    function openLightbox(idx) {
        if (!window.gpxLightbox) return;
        window.gpxLightbox.open(photos.map(p => ({
            full_url: p.full_url, caption: p.caption,
            taken_at: p.taken_at, filename: p.filename
        })), idx);
    }

    // ===== Piny fotek na mapě =====
    function renderPins() {
        photoLayer.clearLayers();
        photos.forEach((p, idx) => {
            const icon = L.divIcon({
                className: '',
                html: `<div class="photo-pin" style="background-image:url('${p.thumb_url}')"></div>`,
                iconSize: [30, 30], iconAnchor: [15, 30]
            });
            const m = L.marker([p.lat, p.lon], { icon });
            m.bindTooltip(
                `${esc(p.caption || p.filename)}<br>${esc(i18n.distance || 'Vzdálenost')}: ${formatDist(p.distance_m)}`,
                { direction: 'top', offset: [0, -28] }
            );
            m.on('click', () => openLightbox(idx));
            photoLayer.addLayer(m);
        });
    }

    // ===== Mřížka výsledků pod mapou =====
    function renderGrid() {
        if (!gridEl || !resultsWrap) return;
        gridEl.innerHTML = '';
        if (!photos.length) { resultsWrap.style.display = 'none'; return; }

        photos.forEach((p, idx) => {
            const card = document.createElement('div');
            card.className = 'pn-card';
            card.title = p.caption || p.filename || '';

            // Odkaz na trasu / virtuální trasu, ke které fotka patří
            let trackHtml = '';
            if (p.track_id) {
                trackHtml = `<div class="pn-track"><a href="detail.php?id=${p.track_id}">🗺 ${esc(p.track_name || ('#' + p.track_id))}</a></div>`;
            } else if (p.virtual_track_id) {
                trackHtml = `<div class="pn-track"><a href="virtual_track_detail.php?id=${p.virtual_track_id}">🧭 ${esc(p.virtual_track_name || ('#' + p.virtual_track_id))}</a></div>`;
            }

            card.innerHTML = `
                <img src="${p.thumb_url}" alt="${esc(p.caption || p.filename || '')}" loading="lazy">
                <div class="pn-meta">
                    <span class="pn-dist">${formatDist(p.distance_m)}</span>
                    <span class="pn-date">${p.taken_at ? window.GpxFmt.formatDate(p.taken_at) : ''}</span>
                    ${p.caption ? `<div class="pn-caption">${esc(p.caption)}</div>` : ''}
                    ${trackHtml}
                </div>`;
            card.addEventListener('click', () => openLightbox(idx));
            // Klik na odkaz trasy nemá otevírat lightbox
            const link = card.querySelector('.pn-track a');
            if (link) link.addEventListener('click', e => e.stopPropagation());
            gridEl.appendChild(card);
        });
        resultsWrap.style.display = 'block';
    }

    // ===== Hledání =====
    async function search(lat, lng) {
        if (isLoading) return;
        isLoading = true;

        const radiusKm = parseFloat(radiusSel?.value || '10');
        const limit    = parseInt(limitSel?.value || '50', 10);

        placeCross(lat, lng);
        placeCircle(lat, lng, radiusKm);
        photoLayer.clearLayers();
        setStatus(i18n.searching || 'Hledám…', 'loading');

        try {
            const res = await fetch(`api/photos/nearby.php?lat=${lat}&lon=${lng}&radius_km=${radiusKm}&limit=${limit}`);
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();

            photos = data.photos || [];
            renderPins();
            renderGrid();

            if (!photos.length) {
                setStatus(i18n.none || 'Žádné fotografie.', 'error');
            } else {
                setStatus((i18n.found || 'Nalezeno fotografií: {n}').replace('{n}', photos.length), 'done');
                const b = L.latLngBounds([[lat, lng]]);
                photos.forEach(p => b.extend([p.lat, p.lon]));
                map.fitBounds(b, { padding: [40, 40] });
            }
        } catch (err) {
            if (window.GPX_DEBUG) console.error('photo-nearby:', err);
            setStatus(i18n.error || 'Chyba.', 'error');
        } finally {
            isLoading = false;
        }
    }

    map.on('click', e => {
        lastPoint = e.latlng;
        search(e.latlng.lat, e.latlng.lng);
    });

    // Změna okruhu / počtu → přepočítat pro poslední kliknutý bod
    if (radiusSel) radiusSel.addEventListener('change', () => { if (lastPoint) search(lastPoint.lat, lastPoint.lng); });
    if (limitSel)  limitSel.addEventListener('change',  () => { if (lastPoint) search(lastPoint.lat, lastPoint.lng); });

});
