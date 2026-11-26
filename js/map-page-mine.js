/**
 * GPX Manager — stránka Mapa: vlastní trasy a fotky jako překryvné vrstvy.
 *
 *  - Moje trasy: čáry tras ve výřezu (api/tracks/lines.php, zjednodušené
 *    na 10 m), od přiblížení 8. Bublina: název, datum, délka, odkaz na detail.
 *  - Moje fotografie: náhledy fotek ve výřezu (api/photos/bounds.php, skryté
 *    fotky vidí jen admin), od přiblížení 12 — níž by jich bylo moc.
 *
 * Co už je na mapě, se při posunu nenačítá znovu (podle id).
 */
(function () {
    "use strict";

    var TRACK_MIN_ZOOM = 8;
    var PHOTO_MIN_ZOOM = 12;
    var TRACK_COLOR    = "#7b2cbf";   // fialová — na turistické i letecké mapě je vidět
    var VT_COLOR       = "#d6336c";   // virtuální trasy: malinová, čárkovaná (odhad z fotek)

    function el(tag, cls, txt) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (txt !== undefined) n.textContent = txt;
        return n;
    }

    function bboxQuery(map) {
        var b = map.getBounds().pad(0.2);
        return "minlat=" + b.getSouth().toFixed(5) + "&maxlat=" + b.getNorth().toFixed(5)
             + "&minlon=" + b.getWest().toFixed(5) + "&maxlon=" + b.getEast().toFixed(5);
    }

    /**
     * Načítání při posunu, jen když je vrstva na mapě. Rychlé posuny
     * za sebou: odpověď na starší dotaz se zahodí.
     */
    function autoLoad(map, group, minZoom, load) {
        var seq = 0;
        function run() {
            if (!map.hasLayer(group) || map.getZoom() < minZoom) return;
            var my = ++seq;
            load(function () { return my === seq; });
        }
        group.on("add", run);
        map.on("moveend", run);
    }

    function fmtDate(iso, lang) {
        if (!iso) return "";
        var d = new Date(String(iso).replace(" ", "T"));
        return isNaN(d) ? String(iso).slice(0, 10) : d.toLocaleDateString(lang);
    }

    function tracksLayer(map, i18n) {
        var group = L.layerGroup();
        var shown = {};
        var lang  = document.documentElement.lang || "cs";

        autoLoad(map, group, TRACK_MIN_ZOOM, function (current) {
            fetch("api/tracks/lines.php?" + bboxQuery(map), { credentials: "same-origin" })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) {
                    if (!d || !current() || !d.tracks) return;
                    d.tracks.forEach(function (t) {
                        if (shown[t.id]) return;
                        shown[t.id] = true;
                        var line = L.polyline(t.lines, {
                            color: TRACK_COLOR, weight: 3, opacity: 0.9, interactive: false
                        });
                        // Tenká čára se špatně trefuje — kliky a najetí myší
                        // chytá neviditelný pás 14 px nad ní
                        var hit = L.polyline(t.lines, { color: "#000", weight: 14, opacity: 0 });
                        var box = el("div", "gpx-mine-pop");
                        box.appendChild(el("strong", "", t.name));
                        var meta = [fmtDate(t.date, lang), t.km ? t.km.toLocaleString(lang) + " km" : "", t.activity]
                            .filter(Boolean).join(" · ");
                        if (meta) box.appendChild(el("div", "gpx-mine-meta", meta));
                        var a = el("a", "", (i18n.trackDetail || "Detail trasy") + " →");
                        a.href = "detail.php?id=" + encodeURIComponent(t.id);
                        box.appendChild(a);
                        hit.bindPopup(box);
                        hit.bindTooltip(el("span", "", t.name), { sticky: true });   // text, ne HTML (N1)
                        hit.on("mouseover", function () { line.setStyle({ weight: 5 }); });
                        hit.on("mouseout",  function () { line.setStyle({ weight: 3 }); });
                        group.addLayer(line);
                        group.addLayer(hit);
                    });
                })
                .catch(function () { /* bez sítě se prostě nic nenačte */ });
        });
        return group;
    }

    function photosLayer(map, i18n) {
        var group = L.layerGroup();
        var shown = {};
        var lang  = document.documentElement.lang || "cs";

        autoLoad(map, group, PHOTO_MIN_ZOOM, function (current) {
            fetch("api/photos/bounds.php?" + bboxQuery(map), { credentials: "same-origin" })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) {
                    if (!d || !current() || !d.photos) return;
                    d.photos.forEach(function (p) {
                        if (shown[p.id]) return;
                        shown[p.id] = true;
                        var img = el("img");
                        img.src = p.thumb_url;
                        img.alt = "";
                        img.loading = "lazy";
                        var wrap = el("div", "gpx-mine-ph");
                        wrap.appendChild(img);
                        var m = L.marker([p.lat, p.lon], {
                            icon: L.divIcon({ className: "gpx-mine-ph-icon", html: wrap, iconSize: [34, 34], iconAnchor: [17, 17] }),
                            keyboard: true,
                            title: fmtDate(p.taken_at, lang)
                        });
                        var box = el("div", "gpx-mine-pop gpx-mine-pop-ph");
                        var big = el("img");
                        big.src = p.thumb_url;
                        big.alt = "";
                        box.appendChild(big);
                        var info = el("div", "gpx-mine-meta", fmtDate(p.taken_at, lang));
                        box.appendChild(info);
                        var a = el("a", "", (i18n.photoOpen || "Otevřít fotku") + " ↗");
                        a.href = p.full_url;
                        a.target = "_blank";
                        a.rel = "noopener";
                        box.appendChild(a);
                        m.bindPopup(box, { maxWidth: 260 });
                        group.addLayer(m);
                    });
                })
                .catch(function () { /* bez sítě se prostě nic nenačte */ });
        });
        return group;
    }

    /** Virtuální trasy: čára přes polohy fotek (api/virtual_tracks/lines.php). */
    function vtracksLayer(map, i18n) {
        var group = L.layerGroup();
        var shown = {};
        var lang  = document.documentElement.lang || "cs";

        autoLoad(map, group, TRACK_MIN_ZOOM, function (current) {
            fetch("api/virtual_tracks/lines.php?" + bboxQuery(map), { credentials: "same-origin" })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) {
                    if (!d || !current() || !d.tracks) return;
                    d.tracks.forEach(function (t) {
                        if (shown[t.id]) return;
                        shown[t.id] = true;
                        var line = L.polyline(t.line, {
                            color: VT_COLOR, weight: 3, opacity: 0.9, dashArray: "6 6", interactive: false
                        });
                        var hit = L.polyline(t.line, { color: "#000", weight: 14, opacity: 0 });
                        var box = el("div", "gpx-mine-pop");
                        box.appendChild(el("strong", "", t.name));
                        var meta = [fmtDate(t.date, lang), t.km ? "≈ " + t.km.toLocaleString(lang) + " km" : "",
                                    t.photos ? "📸 " + t.photos : ""].filter(Boolean).join(" · ");
                        if (meta) box.appendChild(el("div", "gpx-mine-meta", meta));
                        var a = el("a", "", (i18n.vtDetail || "Detail virtuální trasy") + " →");
                        a.href = "virtual_track_detail.php?id=" + encodeURIComponent(t.id);
                        box.appendChild(a);
                        hit.bindPopup(box);
                        hit.bindTooltip(el("span", "", t.name), { sticky: true });   // text, ne HTML (N1)
                        hit.on("mouseover", function () { line.setStyle({ weight: 5 }); });
                        hit.on("mouseout",  function () { line.setStyle({ weight: 3 }); });
                        group.addLayer(line);
                        group.addLayer(hit);
                    });
                })
                .catch(function () { /* bez sítě se prostě nic nenačte */ });
        });
        return group;
    }

    window.GpxMapMine = { tracks: tracksLayer, photos: photosLayer, vtracks: vtracksLayer };
})();
