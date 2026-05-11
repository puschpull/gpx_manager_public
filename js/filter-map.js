/**
 * ===========================================================
 *  GPX Track Cleaner – Map Module
 *  Leaflet mapa: original (sedy) vs vycisteny (barevny)
 * ===========================================================
 */

console.log("filter-map.js nacten");

window.FilterMap = (function () {
    "use strict";

    let map = null;
    let originalLayer = null;
    let removedTrackLayer = null;
    let filteredLayer = null;
    let hoverMarker = null;

    // Vrstvy pro odstranene body (tecky v mape)
    const removedLayers = {
        speed: null,
        spike: null,
        stationary: null,
        elevation: null,
        segment: null,
        simplify: null
    };

    const removedColors = {
        speed: "#e53935",
        spike: "#ff9800",
        stationary: "#fdd835",
        elevation: "#9c27b0",
        segment: "#757575",
        simplify: "#e91e63"
    };

    const removedLabels = {
        speed: "Rychlost",
        spike: "GPS skoky",
        stationary: "Stani",
        elevation: "Vyska",
        segment: "Kratke seg.",
        simplify: "Zjednoduseni"
    };

    let layerControl = null;

    function init() {
        const keys = window.gpxFilterData?.apiKeys || {};
        const tfApiKey       = keys.tf       || "";
        const mapyCOMApiKey  = keys.mapycom  || "";
        const mapillaryToken = keys.mapillary || "";

        map = L.map("filterMap", {
            center: [49.8, 15.5],
            zoom: 8,
            fullscreenControl: true
        });

        // ===== Základní vrstvy (shodné s detail mapou) =====
        const baseOSM = L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            maxZoom: 19, attribution: "© OpenStreetMap"
        }).addTo(map);

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

        // ===== Překryvné vrstvy =====
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
                const res   = await fetch(url);
                const data  = await res.json();
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
                    L.marker([item.lat, item.lon], { icon }).bindPopup(popupEl, { maxWidth: 340 }).addLayer
                    ? wikimediaMarkers.addLayer(L.marker([item.lat, item.lon], { icon }).bindPopup(popupEl, { maxWidth: 340 }))
                    : null;
                }
            } catch(e) { console.error("Wikimedia chyba:", e); }
            finally { wikimediaLoading = false; }
        }
        map.on("moveend zoomend", () => { if (map.hasLayer(wikimediaMarkers)) loadWikimediaPhotos(); });

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
            ...(overlayMapillary ? { "📷 Fotografie (Mapillary)": overlayMapillary } : {}),
            "🖼️ Fotografie (Wikimedia)": wikimediaMarkers,
        };

        // Obnova uložené vrstvy
        const savedLayer = localStorage.getItem("gpx_map_layer");
        if (savedLayer && baseLayers[savedLayer]) {
            map.removeLayer(baseOSM);
            baseLayers[savedLayer].addTo(map);
        }

        map.on("baselayerchange", e => {
            localStorage.setItem("gpx_map_layer", e.name);
        });

        layerControl = L.control.layers(baseLayers, overlayLayers, { collapsed: true }).addTo(map);

        return map;
    }

    function update(originalPoints, filteredPoints, removedByFilter) {
        if (!map) init();

        // Cisteni starych vrstev
        if (originalLayer) { map.removeLayer(originalLayer); originalLayer = null; }
        if (removedTrackLayer) { map.removeLayer(removedTrackLayer); removedTrackLayer = null; }
        if (filteredLayer) { map.removeLayer(filteredLayer); filteredLayer = null; }
        for (const key of Object.keys(removedLayers)) {
            if (removedLayers[key]) {
                map.removeLayer(removedLayers[key]);
                if (layerControl) layerControl.removeLayer(removedLayers[key]);
                removedLayers[key] = null;
            }
        }

        // 1) Cela puvodni trasa - seda (ve vychozim stavu skryta)
        const origSegments = pointsToSegments(originalPoints);
        if (origSegments.length > 0) {
            originalLayer = L.polyline(origSegments, {
                color: "#888",
                weight: 3,
                opacity: 0.5
            });
            // Pridame na mapu jen pokud je checkbox zaskrtnuty
            if (document.getElementById("layerOriginal")?.checked) {
                originalLayer.addTo(map);
            }
        }

        // 2) Odfiltrovaná cast - cervena carkována
        const keptSet = new Set();
        for (const p of filteredPoints) {
            if (p !== null) keptSet.add(p.origIndex);
        }
        const removedTrackPoints = [];
        for (const p of originalPoints) {
            if (p === null) {
                removedTrackPoints.push(null);
            } else if (!keptSet.has(p.origIndex)) {
                removedTrackPoints.push(p);
            } else {
                removedTrackPoints.push(null);
            }
        }
        const removedSegments = pointsToSegments(removedTrackPoints);
        if (removedSegments.length > 0) {
            removedTrackLayer = L.polyline(removedSegments, {
                color: "#e53935",
                weight: 3,
                opacity: 0.6,
                dashArray: "6,8"
            });
            if (document.getElementById("layerRemoved")?.checked) {
                removedTrackLayer.addTo(map);
            }
        }

        // 3) Vycistena trasa - modra plna
        const filtSegments = pointsToSegments(filteredPoints);
        if (filtSegments.length > 0) {
            filteredLayer = L.polyline(filtSegments, {
                color: "#2196F3",
                weight: 4,
                opacity: 0.9
            });
            if (document.getElementById("layerFiltered")?.checked) {
                filteredLayer.addTo(map);
            }
        }

        // Odstranene body - barevne tecky (v layer control, zapinatelne)
        for (const [filterName, points] of Object.entries(removedByFilter)) {
            if (points.length === 0) continue;
            const markers = points.map(p =>
                L.circleMarker([p.lat, p.lon], {
                    radius: 4,
                    color: removedColors[filterName],
                    fillColor: removedColors[filterName],
                    fillOpacity: 0.7,
                    weight: 1
                })
            );
            removedLayers[filterName] = L.layerGroup(markers);
            layerControl.addOverlay(removedLayers[filterName], `${removedLabels[filterName]} (${points.length})`);
        }

        // Zoom na celou viditelnou trasu — jen pri prvnim nacteni
        if (firstUpdate) {
            zoomToVisibleLayers();
            firstUpdate = false;
        }

        // Navazani checkboxu (jednorazove)
        bindLayerCheckboxes();
    }

    function zoomToVisibleLayers() {
        const allBounds = [];
        if (originalLayer && map.hasLayer(originalLayer)) allBounds.push(originalLayer.getBounds());
        if (filteredLayer && map.hasLayer(filteredLayer)) allBounds.push(filteredLayer.getBounds());
        if (removedTrackLayer && map.hasLayer(removedTrackLayer)) allBounds.push(removedTrackLayer.getBounds());
        if (allBounds.length > 0) {
            let bounds = allBounds[0];
            for (let i = 1; i < allBounds.length; i++) {
                bounds.extend(allBounds[i]);
            }
            if (bounds.isValid()) {
                map.fitBounds(bounds, { padding: [20, 20] });
            }
        }
    }

    let firstUpdate = true;
    let checkboxesBound = false;
    function bindLayerCheckboxes() {
        if (checkboxesBound) return;
        checkboxesBound = true;

        const cbOriginal = document.getElementById("layerOriginal");
        const cbFiltered = document.getElementById("layerFiltered");
        const cbRemoved  = document.getElementById("layerRemoved");

        if (cbOriginal) cbOriginal.addEventListener("change", () => {
            if (!originalLayer) return;
            cbOriginal.checked ? originalLayer.addTo(map) : map.removeLayer(originalLayer);
        });
        if (cbFiltered) cbFiltered.addEventListener("change", () => {
            if (!filteredLayer) return;
            cbFiltered.checked ? filteredLayer.addTo(map) : map.removeLayer(filteredLayer);
        });
        if (cbRemoved) cbRemoved.addEventListener("change", () => {
            if (!removedTrackLayer) return;
            cbRemoved.checked ? removedTrackLayer.addTo(map) : map.removeLayer(removedTrackLayer);
        });
    }

    function pointsToSegments(points) {
        const segments = [];
        let current = [];
        for (const p of points) {
            if (p === null) {
                if (current.length > 1) segments.push(current);
                current = [];
            } else {
                current.push([p.lat, p.lon]);
            }
        }
        if (current.length > 1) segments.push(current);
        return segments;
    }

    function moveHoverMarker(lat, lon) {
        if (!map) return;
        if (!hoverMarker) {
            hoverMarker = L.circleMarker([lat, lon], {
                radius: 6,
                color: "#000",
                weight: 2,
                fillColor: "#fff",
                fillOpacity: 1
            }).addTo(map);
        } else {
            hoverMarker.setLatLng([lat, lon]);
        }
    }

    function hideHoverMarker() {
        if (hoverMarker) {
            hoverMarker.removeFrom(map);
            hoverMarker = null;
        }
    }

    function resetZoom() { firstUpdate = true; }

    function getMap() { return map; }

    return {
        init,
        update,
        resetZoom,
        moveHoverMarker,
        hideHoverMarker,
        getMap
    };

})();
