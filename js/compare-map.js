/**
 * ===========================================================
 *  GPX Manager – Compare Tracks Map Module
 *  Zobrazení více GPX tras na jedné mapě pro porovnání
 * ===========================================================
 */

console.log("⚖️ compare-map.js načten");

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
        { maxZoom: 19, opacity: 0.7, attribution: "© Waymarked Trails" }
    );

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
    };

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
                    e.line.bindTooltip(track.name, {
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
