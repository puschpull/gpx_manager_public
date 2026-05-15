/**
 * ===========================================================
 *  GPX Manager – Compare Tracks Map Module
 *  Zobrazení více GPX tras na jedné mapě pro porovnání
 * ===========================================================
 */

console.log("⚖️ compare-map.js načten");

// Delegát na sdílené lib (js/lib/format-utils.js)
function escHtml(s) { return window.GpxFmt.escHtml(s); }

const COMPARE_COLORS = [
    '#e6194b', // červená
    '#3cb44b', // zelená
    '#4363d8', // modrá
    '#f58231', // oranžová
    '#911eb4', // fialová
    '#42d4f4', // azurová
    '#f032e6', // magenta
    '#bfef45', // limetková
    '#fabebe', // světle růžová
    '#469990', // teal
];

document.addEventListener("DOMContentLoaded", () => {

    const data   = window.gpxCompareData;
    const tracks = data?.tracks || [];
    if (tracks.length < 2) return;

    // ===== Inicializace mapy =====
    const map = L.map("map", {
        center: [49.8, 15.5],
        zoom: 8,
        fullscreenControl: true
    });

    // ===== Info o zoomu a středu =====
    const zoomInfo = L.control({ position: "topright" });
    zoomInfo.onAdd = function () {
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

    // ===== API klíče =====
    const keys = data?.apiKeys || {};
    const mapillaryToken = (keys && keys.mapillary) || "";

    // ===== Základní vrstvy via shared factory (js/lib/map-factory.js) =====
    const { baseLayers, overlayLayers, baseOSM } = window.GpxMapFactory.createBaseLayers(keys, map);

    L.control.layers(baseLayers, overlayLayers, { collapsed: true }).addTo(map);

    // ===== Obnova uložené vrstvy =====
    const savedLayer = localStorage.getItem("gpx_map_layer");
    if (savedLayer && baseLayers[savedLayer]) {
        map.removeLayer(baseOSM);
        baseLayers[savedLayer].addTo(map);
    }

    map.on("baselayerchange", e => {
        localStorage.setItem("gpx_map_layer", e.name);
    });

    // ===== Nastavení barev swatchů v legendě a tabulce =====
    tracks.forEach((t, i) => {
        const color = COMPARE_COLORS[i] || '#999';

        // Legenda
        const swatch = document.getElementById(`swatch-${i}`);
        if (swatch) swatch.style.background = color;

        // Tabulka hlavičky
        const thSwatch = document.getElementById(`th-swatch-${i}`);
        if (thSwatch) thSwatch.style.background = color;
    });

    // ===== Načtení GPX tras =====
    const allBounds = L.latLngBounds();
    let loadedCount = 0;

    tracks.forEach((track, i) => {
        const color  = COMPARE_COLORS[i] || '#999';
        const gpxUrl = `uploads/${encodeURIComponent(track.filename)}`;

        fetch(gpxUrl)
            .then(res => {
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                return res.text();
            })
            .then(xmlText => {
                const gpxLayer = new L.GPX(xmlText, {
                    async: true,
                    polyline_options: {
                        color:   color,
                        weight:  4,
                        opacity: 0.85,
                    },
                    marker_options: {
                        startIconUrl: null,
                        endIconUrl:   null,
                        shadowUrl:    null,
                        wptIconUrls:  { '': null },
                    },
                });

                gpxLayer.on("loaded", function (e) {
                    const bounds = e.target.getBounds();
                    if (bounds.isValid()) {
                        allBounds.extend(bounds);
                    }

                    loadedCount++;
                    if (loadedCount === tracks.length) {
                        if (allBounds.isValid()) {
                            map.fitBounds(allBounds, { padding: [30, 30] });
                        }
                    }
                });

                // Tooltip s názvem trasy
                gpxLayer.on("addline", function (e) {
                    e.line.bindTooltip(escHtml(track.name), {
                        sticky:    true,
                        direction: "top",
                        offset:    [0, -10],
                        className: "compare-tooltip",
                    });

                    // Klik na trasu → detail
                    e.line.on("click", () => {
                        window.location.href = `detail.php?id=${track.id}`;
                    });

                    e.line.setStyle({ cursor: "pointer" });
                });

                gpxLayer.addTo(map);
            })
            .catch(err => {
                console.error(`Chyba při načítání ${track.filename}:`, err);
            });
    });
});
