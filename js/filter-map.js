/**
 * ===========================================================
 *  GPX Track Cleaner – Map Module
 *  Leaflet mapa: original (sedy) vs vycisteny (barevny)
 * ===========================================================
 */

if (window.GPX_DEBUG) console.log("filter-map.js nacten");

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
        const mapillaryToken = keys.mapillary || "";

        map = L.map("filterMap", {
            center: [49.8, 15.5],
            zoom: 8,
            fullscreenControl: true
        });

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
        if (window.GpxMapFactory.createLocateControl) window.GpxMapFactory.createLocateControl(map);

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
