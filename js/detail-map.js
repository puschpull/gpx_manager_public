/**
 * ===========================================================
 *  GPX Manager – Detail Map Module
 *  Zobrazení mapy + načtení GPX souboru + marker synchronizace
 * ===========================================================
 */

console.log("🗺️ detail-map.js načten");

// Delegát na sdílené lib (js/lib/format-utils.js)
function escHtml(s) { return window.GpxFmt.escHtml(s); }

let map;
let gpxLayer;
let hoverMarker = null;

document.addEventListener("DOMContentLoaded", () => {

    // ===== Inicializace mapy =====
    map = L.map("map", {
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
    const keys = window.gpxDetailData?.apiKeys || {};
    const mapillaryToken = keys.mapillary || "";

    // ===== Základní vrstvy a Mapillary via shared factory (js/lib/map-factory.js) =====
    const { baseLayers, overlayLayers: _overlayBase, baseOSM } = window.GpxMapFactory.createBaseLayers(keys, map);
    const overlayMapillary = window.GpxMapFactory.createMapillaryOverlay(mapillaryToken);

    // ===== Wikimedia Commons layer via shared factory =====
    const wikimediaMarkers = window.GpxMapFactory.createWikimediaLayer(map);
    function loadWikimediaPhotos() { wikimediaMarkers.loadPhotos(); }

    // ===== Moje fotografie =====
    const myPhotosLayer    = L.layerGroup();
    const photoLineLayer   = L.layerGroup(); // A3 — polohy fotek interpolované na GPX linii
    let   _cachedPhotos    = [];             // Cache pro A3 (pokud GPX přijde až po fotkách)

    // ===== A3: pomocné funkce — interpolace fotek na GPX linii =====
    function parseGpxTrackpoints(xmlText) {
        const parser = new DOMParser();
        const doc    = parser.parseFromString(xmlText, "text/xml");
        const pts    = [];
        doc.querySelectorAll("trkpt").forEach(tp => {
            const lat  = parseFloat(tp.getAttribute("lat"));
            const lon  = parseFloat(tp.getAttribute("lon"));
            const timeEl = tp.querySelector("time");
            if (!timeEl) return;
            const ts = new Date(timeEl.textContent.trim()).getTime();
            if (!isNaN(ts) && !isNaN(lat) && !isNaN(lon)) {
                pts.push({ lat, lon, ts });
            }
        });
        return pts;
    }

    function interpolatePhotoPos(trackpoints, takenAt) {
        if (!takenAt || trackpoints.length === 0) return null;
        // taken_at je "YYYY-MM-DD HH:MM:SS" bez timezone — parsujeme jako lokální čas
        const photoTs = new Date(takenAt.replace(" ", "T")).getTime();
        if (isNaN(photoTs)) return null;
        if (photoTs <= trackpoints[0].ts)
            return { lat: trackpoints[0].lat, lon: trackpoints[0].lon };
        const last = trackpoints[trackpoints.length - 1];
        if (photoTs >= last.ts)
            return { lat: last.lat, lon: last.lon };
        for (let i = 1; i < trackpoints.length; i++) {
            if (photoTs <= trackpoints[i].ts) {
                const p1 = trackpoints[i - 1];
                const p2 = trackpoints[i];
                const t  = (photoTs - p1.ts) / (p2.ts - p1.ts);
                return {
                    lat: p1.lat + t * (p2.lat - p1.lat),
                    lon: p1.lon + t * (p2.lon - p1.lon),
                };
            }
        }
        return null;
    }

    function buildPhotoLineLayer(photos) {
        photoLineLayer.clearLayers();
        const xmlText = window.gpxXMLText;
        if (!xmlText) return;
        const trackpoints = parseGpxTrackpoints(xmlText);
        if (trackpoints.length === 0) return;

        for (const photo of photos) {
            if (!photo.taken_at) continue;
            const pos = interpolatePhotoPos(trackpoints, photo.taken_at);
            if (!pos) continue;

            const circle = L.circleMarker([pos.lat, pos.lon], {
                radius:      5,
                color:       "#e91e63",
                fillColor:   "#e91e63",
                fillOpacity: 0.85,
                weight:      1.5,
                opacity:     0.9,
            });

            const lines = [];
            if (photo.taken_at) lines.push("📅 " + escHtml(photo.taken_at.substring(0, 16)));
            if (photo.caption)  lines.push("💬 " + escHtml(photo.caption));
            circle.bindTooltip(lines.join("<br>") || "📸", { direction: "top", offset: [0, -6] });
            circle.addEventListener("click", ((p) => (e) => {
                if (window.gpxLightbox) {
                    // Navigace přes všechny fotky trasy (stejný seznam jako v markerech)
                    const allList = _cachedPhotos.map(x => ({
                        full_url: x.full_url, alt: x.caption || '', taken_at: x.taken_at || '', id: x.id
                    }));
                    const idx = allList.findIndex(x => x.id === p.id);
                    // A11Y-012: pass trigger so focus returns after close
                    window.gpxLightbox.open(allList, idx >= 0 ? idx : 0, e.target);
                } else {
                    window.open(p.full_url, "_blank");
                }
            })(photo));

            circle.addTo(photoLineLayer);
        }
    }

    async function loadMyPhotos() {
        const trackId = window.gpxDetailData?.trackId;
        if (!trackId) return;

        try {
            const resp = await fetch(`api/photos/track.php?id=${trackId}`);
            const data = await resp.json();
            myPhotosLayer.clearLayers();

            // Seskupit fotky podle zaokrouhlených souřadnic (~11m přesnost)
            const groups = {};
            for (const photo of (data.photos || [])) {
                if (!photo.lat || !photo.lon) continue;
                const key = photo.lat.toFixed(4) + "," + photo.lon.toFixed(4);
                if (!groups[key]) groups[key] = { lat: photo.lat, lon: photo.lon, photos: [] };
                groups[key].photos.push(photo);
            }

            // Zavolej A3 vrstvu (potřebuje photos pole a gpxXMLText)
            _cachedPhotos = data.photos || [];
            buildPhotoLineLayer(_cachedPhotos);

            // Seřazený seznam VŠECH fotek trasy pro navigaci lightboxem (A3 + mapa)
            const allTrackPhotos = _cachedPhotos.map(p => ({
                full_url: p.full_url,
                alt:      p.caption || '',
                taken_at: p.taken_at || '',
                id:       p.id,
            }));
            // Index: photo.id → pozice v allTrackPhotos
            const photoIndexById = {};
            allTrackPhotos.forEach((p, i) => { photoIndexById[p.id] = i; });

            for (const group of Object.values(groups)) {
                const photos = group.photos;
                const count  = photos.length;

                // C2 — šipka směru fotoaparátu (jen pro single foto)
                let dirArrow = "";
                if (count === 1 && photos[0].img_direction !== null && photos[0].img_direction !== undefined) {
                    const deg = photos[0].img_direction;
                    dirArrow = `<svg xmlns="http://www.w3.org/2000/svg"
                        style="position:absolute;top:-16px;left:50%;transform:translateX(-50%) rotate(${deg}deg);pointer-events:none;"
                        width="14" height="18" viewBox="0 0 14 18">
                        <polygon points="7,0 14,18 7,13 0,18" fill="#e91e63" opacity="0.92"/>
                    </svg>`;
                }

                const iconHtml = count > 1
                    ? `<div class="my-photo-marker">📸<span class="photo-badge">${count}</span></div>`
                    : `<div class="my-photo-marker" style="position:relative;">${dirArrow}📸</div>`;

                const iconSz = (count === 1 && dirArrow) ? [30, 46] : [30, 30];
                const icon = L.divIcon({
                    className: "",
                    html: iconHtml,
                    iconSize:   iconSz,
                    iconAnchor: [15, 15],
                });

                const popupEl = document.createElement("div");
                popupEl.style.cssText = "max-width:340px; font-size:13px;";

                if (count === 1) {
                    // Jedna fotka — původní layout
                    const photo = photos[0];
                    const img = document.createElement("img");
                    img.src = photo.thumb_url;
                    img.style.cssText = "width:100%; border-radius:4px; margin-bottom:6px; cursor:pointer;";
                    img.title = "Kliknutím zobrazit plnou velikost";
                    img.onerror = function() { this.style.display = "none"; };
                    img.addEventListener("click", (e) => {
                        if (window.gpxLightbox) {
                            const idx = photoIndexById[photo.id] ?? 0;
                            // A11Y-012: pass trigger so focus returns after close
                            window.gpxLightbox.open(allTrackPhotos, idx, e.currentTarget);
                        } else {
                            window.open(photo.full_url, "_blank");
                        }
                    });
                    popupEl.appendChild(img);

                    // Caption (B2)
                    if (photo.caption) {
                        const capDiv = document.createElement("div");
                        capDiv.style.cssText = "font-size:13px; font-style:italic; margin-bottom:6px; color:var(--text-color,#333);";
                        capDiv.textContent = photo.caption;
                        popupEl.appendChild(capDiv);
                    }

                    const metaDiv = document.createElement("div");
                    metaDiv.style.cssText = "font-size:12px; color:#666; line-height:1.6;";
                    const lines = [];
                    if (photo.taken_at) lines.push("📅 " + photo.taken_at.substring(0, 16).replace("T", " "));
                    if (photo.altitude !== null) lines.push("⛰ " + photo.altitude + " m n. m.");
                    if (photo.img_direction !== null && photo.img_direction !== undefined)
                        lines.push("🧭 " + Math.round(photo.img_direction) + "°");
                    lines.push("📍 " + photo.lat.toFixed(5) + ", " + photo.lon.toFixed(5));
                    metaDiv.innerHTML = lines.map(escHtml).join("<br>");
                    popupEl.appendChild(metaDiv);

                } else {
                    // Více fotek — header + mřížka thumbnailů
                    const header = document.createElement("div");
                    header.style.cssText = "font-weight:600; margin-bottom:8px;";
                    header.textContent = `📸 ${count} fotky z tohoto místa`;
                    popupEl.appendChild(header);

                    const grid = document.createElement("div");
                    grid.style.cssText = "display:grid; grid-template-columns:repeat(3,1fr); gap:4px; margin-bottom:8px;";
                    const photosList = photos.map(p => ({
                        full_url: p.full_url,
                        alt:      '',
                        taken_at: p.taken_at || ''
                    }));
                    for (let idx = 0; idx < photos.length; idx++) {
                        const photo = photos[idx];
                        const img = document.createElement("img");
                        img.src = photo.thumb_url;
                        img.style.cssText = "width:100%; aspect-ratio:1/1; object-fit:cover; border-radius:3px; cursor:pointer;";
                        img.title = photo.taken_at ? photo.taken_at.substring(0, 16).replace("T", " ") : "";
                        img.onerror = function() { this.style.opacity = "0.3"; };
                        img.addEventListener("click", ((p) => (e) => {
                            if (window.gpxLightbox) {
                                const i = photoIndexById[p.id] ?? 0;
                                // A11Y-012: pass trigger so focus returns after close
                                window.gpxLightbox.open(allTrackPhotos, i, e.currentTarget);
                            } else {
                                window.open(p.full_url, "_blank");
                            }
                        })(photo));
                        grid.appendChild(img);
                    }
                    popupEl.appendChild(grid);

                    const metaDiv = document.createElement("div");
                    metaDiv.style.cssText = "font-size:11px; color:#666; line-height:1.6;";
                    const firstDate = photos[0]?.taken_at?.substring(0, 10);
                    const lastDate  = photos[photos.length - 1]?.taken_at?.substring(0, 10);
                    const dateStr   = firstDate && firstDate === lastDate
                        ? "📅 " + escHtml(firstDate)
                        : "📅 " + escHtml(firstDate || "—") + " – " + escHtml(lastDate || "—");
                    metaDiv.innerHTML = dateStr + "<br>📍 " + group.lat.toFixed(5) + ", " + group.lon.toFixed(5);
                    popupEl.appendChild(metaDiv);
                }

                const marker = L.marker([group.lat, group.lon], { icon })
                    .bindPopup(popupEl, { maxWidth: 360 });
                myPhotosLayer.addLayer(marker);
            }
        } catch (err) {
            console.error("⚠️ Chyba načítání fotek:", err);
        }
    }

    // ===== Ovládání vrstev =====
    // baseLayers pochází z GpxMapFactory.createBaseLayers (viz výše)
    const overlayLayers = Object.assign({}, _overlayBase);
    // FE-11: only expose Mapillary layer when token is present and plugin loaded
    if (overlayMapillary) {
        overlayLayers["📷 Fotografie (Mapillary)"] = overlayMapillary;
    }
    Object.assign(overlayLayers, {
        "🖼️ Fotografie (Wikimedia)":          wikimediaMarkers,
        "📸 Moje fotografie":                 myPhotosLayer,
        "📍 Polohy fotek na trase":           photoLineLayer,
    });

    const layerControl = L.control.layers(baseLayers, overlayLayers, { collapsed: true }).addTo(map);

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
            if (name === "📸 Moje fotografie") {
                setTimeout(() => loadMyPhotos(), 500);
            }
            if (name === "📍 Polohy fotek na trase") {
                setTimeout(() => loadMyPhotos(), 500);
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
        if (e.name === "📸 Moje fotografie")         loadMyPhotos();
        if (e.name === "📍 Polohy fotek na trase") {
            if (_cachedPhotos.length > 0) {
                buildPhotoLineLayer(_cachedPhotos);
            } else {
                loadMyPhotos(); // načte fotky a automaticky zavolá buildPhotoLineLayer
            }
        }
    });

    // Jakmile je GPX načten, přepočítej photo-line layer (pokud fotky byly načteny dřív)
    document.addEventListener("gpxDataReady", () => {
        if (_cachedPhotos.length > 0) buildPhotoLineLayer(_cachedPhotos);
    });

    map.on("overlayremove", e => {
        const overlays = JSON.parse(localStorage.getItem("gpx_map_overlays") || "[]");
        const updated  = overlays.filter(n => n !== e.name);
        localStorage.setItem("gpx_map_overlays", JSON.stringify(updated));
    });

    // ===== Načtení GPX souboru =====
    const url = window.gpxDetailData?.gpxUrl;
    if (!url) {
        console.error("❌ Chybí URL GPX souboru.");
        return;
    }

    console.log("📥 Načítám GPX:", url);

    fetch(url)
        .then(res => {
            if (!res.ok) throw new Error("HTTP " + res.status);
            return res.text();
        })
        .then(xmlText => {
            console.log("📦 GPX soubor úspěšně načten.");

gpxLayer = new L.GPX(xmlText, {
                async: true,
                marker_options: {
                    startIconUrl: "https://unpkg.com/leaflet-gpx@1.7.0/pin-icon-start.png",
                    endIconUrl:   "https://unpkg.com/leaflet-gpx@1.7.0/pin-icon-end.png",
                    shadowUrl:    "https://unpkg.com/leaflet-gpx@1.7.0/pin-shadow.png"
                },
                polyline_options: {
                    color:   window.strokeColor,
                    weight:  4,
                    opacity: 0.9
                }
            })
            .on("loaded", e => {
                const bounds = e.target.getBounds();
                if (bounds && bounds.isValid()) {
                    map.fitBounds(bounds, { padding: [10, 10] });
                }

                // Přidání trasy do ovládání vrstev
                layerControl.addOverlay(gpxLayer, "🗺️ Moje trasa");

                // Obnova stavu z localStorage
                const savedOverlaysNow = JSON.parse(localStorage.getItem("gpx_map_overlays") || "[]");
                if (!savedOverlaysNow.includes("🗺️ Moje trasa")) {
                    gpxLayer.addTo(map);
                }

                window.gpxXMLText = xmlText;
                document.dispatchEvent(new CustomEvent("gpxDataReady", { detail: { xmlText } }));
                console.log("📡 Událost 'gpxDataReady' odeslána.");
            })
            .addTo(map);
        })
        .catch(err => console.error("⚠️ Chyba při načítání GPX:", err));

});

// ===== Pomocné funkce pro hover marker =====
function ensureHoverMarker() {
    if (!hoverMarker) {
        hoverMarker = L.circleMarker([0, 0], {
            radius:      6,
            color:       "#000",
            weight:      2,
            fillColor:   "#fff",
            fillOpacity: 1
        }).addTo(map);
    }
}

window.moveHoverMarker = function (lat, lon) {
    ensureHoverMarker();
    hoverMarker.setLatLng([lat, lon]);
};

window.hideHoverMarker = function () {
    if (hoverMarker) {
        hoverMarker.removeFrom(map);
        hoverMarker = null;
    }
};