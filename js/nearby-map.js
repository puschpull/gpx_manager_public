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
    const tfApiKey       = keys.tf || "";
    const mapyCOMApiKey  = keys.mapycom || "";
    const mapillaryToken = keys.mapillary || "";

    // ===== Základní vrstvy =====
    const baseOSM = L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19,
        attribution: "© OpenStreetMap"
    }).addTo(map);

    const baseTopo = L.tileLayer("https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png", {
        maxZoom: 17,
        attribution: "© OpenTopoMap"
    });

    const baseSat = L.tileLayer(
        "https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}",
        { maxZoom: 19, attribution: "© Esri" }
    );

    const baseMapyCOMBasic = L.tileLayer(
        `https://api.mapy.com/v1/maptiles/basic/256/{z}/{x}/{y}?apikey=${mapyCOMApiKey}`,
        {
            maxZoom: 19,
            attribution: '© <a href="https://mapy.com" target="_blank">Mapy.com</a>, © OpenStreetMap'
        }
    );

    const baseMapyCOMTurist = L.tileLayer(
        `https://api.mapy.com/v1/maptiles/outdoor/256/{z}/{x}/{y}?apikey=${mapyCOMApiKey}`,
        {
            maxZoom: 19,
            attribution: '© <a href="https://mapy.com" target="_blank">Mapy.com</a>, © OpenStreetMap'
        }
    );

    const baseMapyCOMWinter = L.tileLayer(
        `https://api.mapy.com/v1/maptiles/winter/256/{z}/{x}/{y}?apikey=${mapyCOMApiKey}`,
        {
            maxZoom: 19,
            attribution: '© <a href="https://mapy.com" target="_blank">Mapy.com</a>, © OpenStreetMap'
        }
    );

    const baseMapyCOMAerial = L.tileLayer(
        `https://api.mapy.com/v1/maptiles/aerial/256/{z}/{x}/{y}?apikey=${mapyCOMApiKey}`,
        {
            maxZoom: 20,
            attribution: '© <a href="https://mapy.com" target="_blank">Mapy.com</a>'
        }
    );

    const baseThunderforest = L.tileLayer(
        `https://{s}.tile.thunderforest.com/outdoors/{z}/{x}/{y}.png?apikey=${tfApiKey}`,
        {
            maxZoom: 22,
            attribution: "© Thunderforest, © OpenStreetMap"
        }
    );

    // ===== Překryvné vrstvy (overlay) =====
    const overlayWaymarked = L.tileLayer(
        "https://tile.waymarkedtrails.org/hiking/{z}/{x}/{y}.png",
        {
            maxZoom: 19,
            opacity: 0.7,
            attribution: "© Waymarked Trails, © OpenStreetMap"
        }
    );

    const overlayMapillary = L.vectorGrid.protobuf(
        `https://tiles.mapillary.com/maps/vtp/mly1_public/2/{z}/{x}/{y}?access_token=${mapillaryToken}`,
        {
            vectorTileLayerStyles: {
                sequence: { weight: 2, color: "#05CB63", opacity: 0.8, fill: false },
                image:    { radius: 3, fillColor: "#05CB63", fillOpacity: 0.8, color: "#fff", weight: 1, fill: true },
                overview: { weight: 2, color: "#05CB63", opacity: 0.6, fill: false }
            },
            interactive: true,
            maxNativeZoom: 14
        }
    );

    overlayMapillary.on("click", function(e) {
        const props = e.layer.properties;
        if (props && props.image_id) {
            const url = `https://www.mapillary.com/app/?focus=photo&pKey=${props.image_id}`;
            const w = 800, h = 600;
            const left = Math.round((screen.width - w) / 2);
            const top  = Math.round((screen.height - h) / 2);
            window.open(url, "mapillary_photo",
                `width=${w},height=${h},left=${left},top=${top},resizable=yes,scrollbars=yes`
            );
        }
    });

    // ===== Wikimedia Commons =====
    const wikimediaMarkers = L.layerGroup();
    let wikimediaLoading = false;

    async function loadWikimediaPhotos() {
        if (wikimediaLoading) return;
        if (!map.hasLayer(wikimediaMarkers)) return;

        const zoom = map.getZoom();
        if (zoom < 12) {
            wikimediaMarkers.clearLayers();
            return;
        }

        wikimediaLoading = true;
        const bounds = map.getBounds();
        const url = `https://commons.wikimedia.org/w/api.php?` +
            `action=query&list=geosearch&gscoord=${bounds.getCenter().lat}|${bounds.getCenter().lng}` +
            `&gsradius=10000&gslimit=50&gsnamespace=6` +
            `&prop=imageinfo&iiprop=url|extmetadata` +
            `&format=json&origin=*`;

        try {
            const res   = await fetch(url);
            const data  = await res.json();
            const items = data?.query?.geosearch || [];

            wikimediaMarkers.clearLayers();
            const validItems = items.filter(item => item.lat && item.lon);

            const infoPromises = validItems.map(item => {
                const infoUrl = `https://commons.wikimedia.org/w/api.php?` +
                    `action=query&titles=${encodeURIComponent(item.title)}` +
                    `&prop=imageinfo&iiprop=url|thumburl|extmetadata` +
                    `&iiurlwidth=400&format=json&origin=*`;
                return fetch(infoUrl).then(r => r.json()).then(d => ({ item, data: d })).catch(() => null);
            });

            const results = await Promise.all(infoPromises);

            for (const result of results) {
                if (!result) continue;
                const { item, data: d } = result;
                const pages = d?.query?.pages || {};
                const page  = Object.values(pages)[0];
                const info  = page?.imageinfo?.[0];
                if (!info) continue;

                const thumbUrl = (info.thumburl || info.url || "").replace(/["<>]/g, "");
                const title    = (page.title || "").replace("File:", "").replace(/[<>]/g, "");
                const pageUrl  = `https://commons.wikimedia.org/wiki/${encodeURIComponent(page.title)}`;

                const icon = L.divIcon({
                    className: '',
                    html: `<div class="wiki-marker">📷</div>`,
                    iconSize:   [28, 28],
                    iconAnchor: [14, 14],
                });

                const popupEl = document.createElement("div");
                popupEl.style.cssText = "max-width:320px; font-size:13px;";

                const img = document.createElement("img");
                img.src = thumbUrl;
                img.style.cssText = "width:100%; border-radius:4px; margin-bottom:6px;";
                img.onerror = function() { this.style.display = "none"; };
                popupEl.appendChild(img);

                const titleDiv = document.createElement("div");
                titleDiv.style.cssText = "font-weight:600; margin-bottom:4px;";
                titleDiv.textContent = title;
                popupEl.appendChild(titleDiv);

                const link = document.createElement("a");
                link.href = pageUrl;
                link.target = "_blank";
                link.style.cssText = "color:#0078d7; font-size:12px;";
                link.textContent = "Zobrazit na Wikimedia Commons \u2192";
                popupEl.appendChild(link);

                const marker = L.marker([item.lat, item.lon], { icon })
                    .bindPopup(popupEl, { maxWidth: 340 });

                wikimediaMarkers.addLayer(marker);
            }
        } catch (err) {
            console.error("⚠️ Wikimedia chyba:", err);
        } finally {
            wikimediaLoading = false;
        }
    }

    map.on("moveend zoomend", () => {
        if (map.hasLayer(wikimediaMarkers)) loadWikimediaPhotos();
    });

    // ===== Ovládání vrstev =====
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
        "📷 Fotografie (Mapillary)":          overlayMapillary,
        "🖼️ Fotografie (Wikimedia)":          wikimediaMarkers,
    };

    L.control.layers(baseLayers, overlayLayers, { collapsed: true }).addTo(map);

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
