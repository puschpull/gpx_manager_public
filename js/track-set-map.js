/**
 * GPX Manager — map of a set of tracks (year summary, collection).
 *
 * GpxTrackSetMap.create(elementId, { linesUrl, apiKeys, i18n, color })
 * loads simplified lines from linesUrl ({ ok, tracks: [{id, name, date, km, lines}] }),
 * draws them, fits the view and binds a popup with a link to the track detail.
 * Returns a promise with the list of tracks (for page-specific extras).
 */
window.GpxTrackSetMap = (function () {
    "use strict";

    var PALETTE = ["#7b2cbf", "#c0392b", "#1f77b4", "#2e8b57", "#d35400", "#8e44ad", "#16a085", "#b7950b"];

    function el(tag, cls, txt) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (txt !== undefined) n.textContent = txt;
        return n;
    }

    function create(elementId, opts) {
        var box = document.getElementById(elementId);
        if (!box || !window.L) return Promise.resolve([]);
        var i18n = opts.i18n || {};
        var map = L.map(box, { scrollWheelZoom: false }).setView([50.0, 15.0], 7);
        var F = window.GpxMapFactory;
        if (F) {
            var layers = F.createBaseLayers(opts.apiKeys || {}, map);
            L.control.layers(layers.baseLayers, {}, { collapsed: true }).addTo(map);
        } else {
            L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19, attribution: "© OpenStreetMap" }).addTo(map);
        }
        // Scroll zoom only after the user clicks into the map (page scrolls freely)
        map.on("click", function () { map.scrollWheelZoom.enable(); });
        map.on("mouseout", function () { map.scrollWheelZoom.disable(); });

        var lang = document.documentElement.lang || "cs";
        return fetch(opts.linesUrl, { credentials: "same-origin" })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d || !d.tracks || !d.tracks.length) return [];
                var bounds = L.latLngBounds([]);
                d.tracks.forEach(function (t, i) {
                    var color = opts.color || PALETTE[i % PALETTE.length];
                    var line = L.polyline(t.lines, { color: color, weight: 3, opacity: 0.85, interactive: false }).addTo(map);
                    var hit = L.polyline(t.lines, { color: "#000", weight: 14, opacity: 0 }).addTo(map);
                    var pop = el("div");
                    pop.appendChild(el("strong", "", t.name));
                    var meta = [t.date ? new Date(t.date).toLocaleDateString(lang) : "", t.km ? t.km.toLocaleString(lang) + " km" : ""]
                        .filter(Boolean).join(" · ");
                    if (meta) pop.appendChild(el("div", "", meta));
                    var a = el("a", "", (i18n.trackDetail || "Detail") + " →");
                    a.href = "detail.php?id=" + encodeURIComponent(t.id);
                    pop.appendChild(a);
                    hit.bindPopup(pop);
                    hit.bindTooltip(el("span", "", t.name), { sticky: true });   // text, ne HTML (N1)
                    hit.on("mouseover", function () { line.setStyle({ weight: 6 }); });
                    hit.on("mouseout", function () { line.setStyle({ weight: 3 }); });
                    bounds.extend(line.getBounds());
                });
                if (bounds.isValid()) map.fitBounds(bounds, { padding: [20, 20] });
                return d.tracks;
            })
            .catch(function () { return []; });
    }

    return { create: create };
})();
