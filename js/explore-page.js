/**
 * GPX Manager — explored squares (explore.php).
 *
 * Draws visited zoom-14 tiles from api/explore/tiles.php on a canvas renderer
 * (thousands of rectangles), the largest cluster, the largest square and an
 * optional grid of all tiles. Filters: activity, year to highlight.
 */
(function () {
    "use strict";

    var Z = 14;
    var N = Math.pow(2, Z);
    var COL = { tile: "#7b2cbf", cluster: "#1f5fbf", fresh: "#e8590c", square: "#e8590c", grid: "rgba(60,60,60,.35)" };

    function lon(x) { return x / N * 360 - 180; }
    function lat(y) { var n = Math.PI - 2 * Math.PI * y / N; return 180 / Math.PI * Math.atan(0.5 * (Math.exp(n) - Math.exp(-n))); }
    function bounds(x, y, w) { return [[lat(y + w), lon(x)], [lat(y), lon(x + w)]]; }

    /** Grid of zoom-14 tile borders, drawn per map tile. */
    var Grid = L.GridLayer.extend({
        createTile: function (c) {
            var tile = document.createElement("canvas");
            var size = this.getTileSize();
            tile.width = size.x; tile.height = size.y;
            var ctx = tile.getContext("2d");
            ctx.strokeStyle = COL.grid; ctx.lineWidth = 1;
            ctx.beginPath();
            if (c.z <= Z) {
                var per = Math.pow(2, Z - c.z), step = size.x / per;
                if (step >= 6) {
                    for (var i = 0; i < per; i++) {
                        ctx.moveTo(i * step + 0.5, 0); ctx.lineTo(i * step + 0.5, size.y);
                        ctx.moveTo(0, i * step + 0.5); ctx.lineTo(size.x, i * step + 0.5);
                    }
                }
            } else {
                var f = Math.pow(2, c.z - Z);
                if (c.x % f === 0) { ctx.moveTo(0.5, 0); ctx.lineTo(0.5, size.y); }
                if (c.y % f === 0) { ctx.moveTo(0, 0.5); ctx.lineTo(size.x, 0.5); }
            }
            ctx.stroke();
            return tile;
        }
    });

    document.addEventListener("DOMContentLoaded", function () {
        var D = window.gpxExploreData;
        var box = document.getElementById("exMap");
        if (!D || !box || !window.L) return;

        var map = L.map(box, { preferCanvas: true }).setView([50.3, 14.5], 8);
        var F = window.GpxMapFactory;
        var base = F ? F.createBaseLayers(D.apiKeys || {}, map) : null;
        var renderer = L.canvas({ padding: 0.3 });
        var layer = L.layerGroup().addTo(map);
        var grid = new Grid({ minZoom: 9, maxZoom: 19, opacity: 1, zIndex: 300 });
        var overlays = {};
        overlays[D.i18n.grid] = grid;
        if (base) L.control.layers(base.baseLayers, overlays, { collapsed: true }).addTo(map);

        var act = document.getElementById("exAct");
        var year = document.getElementById("exYear");
        var status = document.getElementById("exStatus");
        var first = true;

        function setText(id, v) { var n = document.getElementById(id); if (n) n.textContent = v; }

        function load() {
            status.textContent = D.i18n.loading;
            var url = "api/explore/tiles.php?act=" + encodeURIComponent(act.value) + (year.value ? "&year=" + encodeURIComponent(year.value) : "");
            fetch(url, { credentials: "same-origin" })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) {
                    if (!d || !d.ok) { status.textContent = D.i18n.error; return; }
                    layer.clearLayers();
                    var inCluster = {};
                    d.cluster.forEach(function (c) { inCluster[c[0] + "," + c[1]] = true; });
                    var hy = year.value ? parseInt(year.value, 10) : null;
                    var all = L.latLngBounds([]);
                    d.tiles.forEach(function (t) {
                        var k = t[0] + "," + t[1];
                        var fresh = hy !== null && t[3] === hy;
                        var color = fresh ? COL.fresh : (inCluster[k] ? COL.cluster : COL.tile);
                        var r = L.rectangle(bounds(t[0], t[1], 1), {
                            renderer: renderer, color: color, weight: 0.6, opacity: 0.7,
                            fillColor: color, fillOpacity: fresh ? 0.45 : 0.28
                        });
                        r.bindTooltip(D.i18n.tileTip.replace("{n}", t[2]).replace("{y}", t[3] || "—"), { sticky: true });
                        layer.addLayer(r);
                        all.extend(r.getBounds());
                    });
                    if (d.square.size > 0) {
                        layer.addLayer(L.rectangle(bounds(d.square.x, d.square.y, d.square.size), {
                            renderer: renderer, color: COL.square, weight: 3, fill: false, dashArray: "6 4", interactive: false
                        }));
                    }
                    setText("exTotal", d.total.toLocaleString());
                    setText("exCluster", d.cluster.length.toLocaleString());
                    setText("exSquare", d.square.size + " × " + d.square.size);
                    setText("exNew", hy !== null ? d.new_count.toLocaleString() : "—");
                    status.textContent = d.complete ? "" : D.i18n.incomplete;
                    if (first && d.cluster.length) {
                        var cb = L.latLngBounds([]);
                        d.cluster.forEach(function (c) { cb.extend(bounds(c[0], c[1], 1)); });
                        map.fitBounds(cb.pad(0.3));
                    } else if (first && all.isValid()) {
                        map.fitBounds(all);
                    }
                    first = false;
                })
                .catch(function () { status.textContent = D.i18n.error; });
        }

        act.addEventListener("change", load);
        year.addEventListener("change", load);
        load();
    });
})();
