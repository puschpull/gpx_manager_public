/**
 * ===========================================================
 *  GPX Manager – Nearby Tracks Map Module
 *  Mapa pro hledání nejbližších tras kliknutím na bod
 * ===========================================================
 */

console.log("🗺️ nearby-map.js načten");

document.addEventListener("DOMContentLoaded", () => {

    // ===== Inicializace mapy =====
    const map = L.map("map", {
        center: [49.8, 15.5],
        zoom: 8,
        fullscreenControl: true
    });

    // ===== Info o zoomu a středu =====
    const zoomInfo = L.control({ position: "topright" });
    zoomInfo.onAdd = function (map) {
        const div = L.DomUtil.create("div", "zoom-info");
        const c = map.getCenter();
        div.innerHTML =
            `📍 <strong>Lat:</strong> ${c.lat.toFixed(5)}, ` +
            `<strong>Lon:</strong> ${c.lng.toFixed(5)}<br>` +
            `🔍 <strong>Zoom:</strong> ${map.getZoom()}`;
        return div;
    };
    zoomInfo.addTo(map);

    function updateZoomInfo() {
        const el = document.querySelector(".zoom-info");
        if (!el) return;
        const c = map.getCenter();
        el.innerHTML =
            `📍 <strong>Lat:</strong> ${c.lat.toFixed(5)}, ` +
            `<strong>Lon:</strong> ${c.lng.toFixed(5)}<br>` +
            `🔍 <strong>Zoom:</strong> ${map.getZoom()}`;
    }
    map.on("zoomend moveend", updateZoomInfo);

    // ===== API klíče (ze serveru) =====
    const keys = window.gpxNearbyData?.apiKeys || {};
    const mapillaryToken = keys.mapillary || "";

    // ===== Základní vrstvy a Mapillary via shared factory (js/lib/map-factory.js) =====
    const { baseLayers, overlayLayers: _overlayBase, baseOSM } = window.GpxMapFactory.createBaseLayers(keys, map);
    const overlayMapillary = window.GpxMapFactory.createMapillaryOverlay(mapillaryToken);

    // ===== Wikimedia Commons layer via shared factory =====
    const wikimediaMarkers = window.GpxMapFactory.createWikimediaLayer(map);
    function loadWikimediaPhotos() { wikimediaMarkers.loadPhotos(); }

    const overlayLayers = Object.assign({}, _overlayBase,
        overlayMapillary ? { "📷 Fotografie (Mapillary)": overlayMapillary } : {},
        { "🖼️ Fotografie (Wikimedia)": wikimediaMarkers }
    );

    L.control.layers(baseLayers, overlayLayers, { collapsed: true }).addTo(map);
    if (window.GpxMapFactory.createLocateControl) window.GpxMapFactory.createLocateControl(map);

    // ===== Obnova uložené vrstvy =====
    const savedLayer = localStorage.getItem("gpx_map_layer");
    if (savedLayer && baseLayers[savedLayer]) {
        map.removeLayer(baseOSM);
        baseLayers[savedLayer].addTo(map);
    }

    const savedOverlays = JSON.parse(localStorage.getItem("gpx_map_overlays") || "[]");
    savedOverlays.forEach(name => {
        if (overlayLayers[name]) {
            overlayLayers[name].addTo(map);
            if (name === "🖼️ Fotografie (Wikimedia)") {
                setTimeout(() => loadWikimediaPhotos(), 1500);
            }
        }
    });

    // ===== Ukládání zvolené vrstvy =====
    map.on("baselayerchange", e => {
        localStorage.setItem("gpx_map_layer", e.name);
    });

    map.on("overlayadd", e => {
        const overlays = JSON.parse(localStorage.getItem("gpx_map_overlays") || "[]");
        if (!overlays.includes(e.name)) overlays.push(e.name);
        localStorage.setItem("gpx_map_overlays", JSON.stringify(overlays));
        if (e.name === "🖼️ Fotografie (Wikimedia)") loadWikimediaPhotos();
    });

    map.on("overlayremove", e => {
        const overlays = JSON.parse(localStorage.getItem("gpx_map_overlays") || "[]");
        const updated  = overlays.filter(n => n !== e.name);
        localStorage.setItem("gpx_map_overlays", JSON.stringify(updated));
    });

    // ===== Stav aplikace =====
    let clickMarker = null;
    let trackLayers = [];
    let nearestMarkers = [];
    let isLoading = false;

    const statusEl = document.getElementById("nearby-status");

    function setStatus(text, cls) {
        if (!statusEl) return;
        statusEl.textContent = text;
        statusEl.className = "nearby-status" + (cls ? " " + cls : "");
    }

    // ===== Vyčistit předchozí výsledky =====
    function clearResults() {
        trackLayers.forEach(layer => map.removeLayer(layer));
        trackLayers = [];
        nearestMarkers.forEach(m => map.removeLayer(m));
        nearestMarkers = [];
    }

    // ===== Kliknutí na mapu =====
    map.on("click", async (e) => {
        if (isLoading) return;

        const { lat, lng } = e.latlng;

        // Umístit/přesunout osový kříž na kliknutý bod
        if (clickMarker) {
            clickMarker.setLatLng([lat, lng]);
        } else {
            const size = 30; // ~8mm na monitoru (96dpi)
            const half = size / 2;
            const crossSvg = `
                <svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" xmlns="http://www.w3.org/2000/svg">
                    <!-- vodorovná čára -->
                    <line x1="0" y1="${half}" x2="${size}" y2="${half}" stroke="#000" stroke-width="3"/>
                    <line x1="0" y1="${half}" x2="${size}" y2="${half}" stroke="#ff0000" stroke-width="1.5"/>
                    <!-- svislá čára -->
                    <line x1="${half}" y1="0" x2="${half}" y2="${size}" stroke="#000" stroke-width="3"/>
                    <line x1="${half}" y1="0" x2="${half}" y2="${size}" stroke="#ff0000" stroke-width="1.5"/>
                    <!-- střed -->
                    <circle cx="${half}" cy="${half}" r="3" fill="#ff0000" stroke="#000" stroke-width="1.5"/>
                </svg>`;
            clickMarker = L.marker([lat, lng], {
                icon: L.divIcon({
                    className: '',
                    html: crossSvg,
                    iconSize: [size, size],
                    iconAnchor: [half, half],
                }),
                zIndexOffset: 1000
            }).addTo(map);
        }

        // Vyčistit předchozí
        clearResults();

        // Načíst nejbližší trasy
        isLoading = true;
        setStatus(`Hledám nejbližší trasy k bodu [${lat.toFixed(5)}, ${lng.toFixed(5)}]...`, "loading");

        try {
            const res = await fetch(`nearby.php?ajax=1&lat=${lat}&lon=${lng}`);
            if (!res.ok) throw new Error("HTTP " + res.status);
            const data = await res.json();

            if (data.error) {
                setStatus("Chyba: " + data.error, "error");
                isLoading = false;
                return;
            }

            const tracks = data.tracks || [];
            if (tracks.length === 0) {
                setStatus("Nebyly nalezeny žádné trasy.", "error");
                isLoading = false;
                return;
            }

            setStatus(`Nalezeno ${tracks.length} nejbližších tras. Načítám GPX soubory...`, "loading");

            // Načíst a vykreslit GPX soubory
            const bounds = L.latLngBounds([[lat, lng]]);
            let loadedCount = 0;

            tracks.forEach((track, i) => {
                const color = NEARBY_COLORS[i] || '#999';
                const gpxUrl = `uploads/${encodeURIComponent(track.filename)}`;

                fetch(gpxUrl)
                    .then(r => {
                        if (!r.ok) throw new Error("HTTP " + r.status);
                        return r.text();
                    })
                    .then(xmlText => {
                        const gpxLayer = new L.GPX(xmlText, {
                            async: true,
                            marker_options: {
                                startIconUrl: null,
                                endIconUrl:   null,
                                shadowUrl:    null
                            },
                            polyline_options: {
                                color:   color,
                                weight:  4,
                                opacity: 0.8
                            }
                        })
                        .on("loaded", (ev) => {
                            const trackBounds = ev.target.getBounds();
                            if (trackBounds && trackBounds.isValid()) {
                                bounds.extend(trackBounds);
                            }

                            loadedCount++;
                            if (loadedCount === tracks.length) {
                                map.fitBounds(bounds, { padding: [30, 30] });
                                setStatus(`Zobrazeno ${tracks.length} nejbližších tras.`, "done");
                                isLoading = false;
                            }
                        })
                        .on("click", () => {
                            window.location.href = `detail.php?id=${track.id}`;
                        })
                        .addTo(map);

                        // Tooltip s názvem trasy
                        gpxLayer.bindTooltip(
                            `<strong>${escapeHtml(track.track_name || track.filename)}</strong><br>` +
                            `Vzdálenost od bodu: ${track.distance_from_point} km`,
                            { sticky: true }
                        );

                        trackLayers.push(gpxLayer);
                    })
                    .catch(err => {
                        console.warn(`⚠️ Nelze načíst GPX: ${track.filename}`, err);
                        loadedCount++;
                        if (loadedCount === tracks.length) {
                            map.fitBounds(bounds, { padding: [30, 30] });
                            setStatus(`Zobrazeno ${trackLayers.length} z ${tracks.length} tras.`, "done");
                            isLoading = false;
                        }
                    });

                // Přidat značku nejbližšího bodu na trase
                if (track.nearest_lat && track.nearest_lon) {
                    const nearestMarker = L.circleMarker([track.nearest_lat, track.nearest_lon], {
                        radius: 6,
                        color: color,
                        weight: 2,
                        fillColor: '#fff',
                        fillOpacity: 1,
                        zIndexOffset: 500
                    }).addTo(map);
                    nearestMarker.bindTooltip(
                        `Nejbližší bod: ${escapeHtml(track.track_name || track.filename)}`,
                        { direction: 'top' }
                    );
                    nearestMarkers.push(nearestMarker);
                }
            });

            // Vykreslit tabulku
            renderNearbyTable(tracks);

        } catch (err) {
            console.error("⚠️ Chyba při hledání nejbližších tras:", err);
            setStatus("Chyba při komunikaci se serverem.", "error");
            isLoading = false;
        }
    });

});
