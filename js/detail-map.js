/**
 * ===========================================================
 *  GPX Manager – Detail Map Module
 *  Zobrazení mapy + načtení GPX souboru + marker synchronizace
 * ===========================================================
 */

console.log("🗺️ detail-map.js načten");

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
                sequence: {
                    weight: 2,
                    color: "#05CB63",
                    opacity: 0.8,
                    fill: false
                },
                image: {
                    radius: 3,
                    fillColor: "#05CB63",
                    fillOpacity: 0.8,
                    color: "#fff",
                    weight: 1,
                    fill: true
                },
                overview: {
                    weight: 2,
                    color: "#05CB63",
                    opacity: 0.6,
                    fill: false
                }
            },
            interactive: true,
            maxNativeZoom: 14
        }
    );

    overlayMapillary.on("click", function(e) {
        const props = e.layer.properties;
        if (props && props.image_id) {
            const url = `https://www.mapillary.com/app/?focus=photo&pKey=${props.image_id}`;
            const w    = 800, h = 600;
            const left = Math.round((screen.width  - w) / 2);
            const top  = Math.round((screen.height - h) / 2);
            window.open(url, "mapillary_photo",
                `width=${w},height=${h},left=${left},top=${top},resizable=yes,scrollbars=yes`
            );
        }
    });

    // ===== Wikimedia Commons =====
    const wikimediaMarkers = L.layerGroup();
    let wikimediaLoading   = false;

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
            if (photo.taken_at) lines.push("📅 " + photo.taken_at.substring(0, 16));
            if (photo.caption)  lines.push("💬 " + photo.caption);
            circle.bindTooltip(lines.join("<br>") || "📸", { direction: "top", offset: [0, -6] });
            circle.addEventListener("click", ((p) => () => {
                if (window.gpxLightbox) {
                    // Navigace přes všechny fotky trasy (stejný seznam jako v markerech)
                    const allList = _cachedPhotos.map(x => ({
                        full_url: x.full_url, alt: x.caption || '', taken_at: x.taken_at || '', id: x.id
                    }));
                    const idx = allList.findIndex(x => x.id === p.id);
                    window.gpxLightbox.open(allList, idx >= 0 ? idx : 0);
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
            const resp = await fetch(`photos.php?ajax=track&id=${trackId}`);
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
                    img.addEventListener("click", () => {
                        if (window.gpxLightbox) {
                            const idx = photoIndexById[photo.id] ?? 0;
                            window.gpxLightbox.open(allTrackPhotos, idx);
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
                    metaDiv.innerHTML = lines.join("<br>");
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
                        img.addEventListener("click", ((p) => () => {
                            if (window.gpxLightbox) {
                                const i = photoIndexById[p.id] ?? 0;
                                window.gpxLightbox.open(allTrackPhotos, i);
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
                        ? "📅 " + firstDate
                        : "📅 " + (firstDate || "—") + " – " + (lastDate || "—");
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
        "📸 Moje fotografie":                 myPhotosLayer,
        "📍 Polohy fotek na trase":           photoLineLayer,
    };

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