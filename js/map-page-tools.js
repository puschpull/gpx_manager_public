/**
 * GPX Manager — stránka Mapa: souřadnice a měření.
 *
 *  - Vlevo dole souřadnice místa pod kurzorem (bez myši střed mapy),
 *    ve stupních a v S-JTSK (EPSG:5514, jak je používá ČÚZK).
 *  - Pravé tlačítko (na mobilu podržení prstu) otevře bublinu se
 *    souřadnicemi v několika tvarech a tlačítky ke zkopírování.
 *  - Tlačítko 📏 zapne měření: klikáním se přidávají body, ukazuje se
 *    délka lomené čáry a od tří bodů i plocha uzavřeného mnohoúhelníku.
 *
 * Převod do S-JTSK je vlastní (Helmertova transformace na Besselův
 * elipsoid + Křovákovo zobrazení), bez další knihovny; od PROJ
 * (EPSG:1623) se liší do 0,2 m.
 */
(function () {
    "use strict";

    var D2R = Math.PI / 180;

    /* ===== WGS84 → S-JTSK (EPSG:5514) ===== */
    function toJtsk(lat, lon) {
        // WGS84 zeměpisné → pravoúhlé (výška 0)
        var a = 6378137.0, f = 1 / 298.257223563, e2 = 1 - (1 - f) * (1 - f);
        var B = lat * D2R, L = lon * D2R;
        var N = a / Math.sqrt(1 - e2 * Math.sin(B) * Math.sin(B));
        var x = N * Math.cos(B) * Math.cos(L);
        var y = N * Math.cos(B) * Math.sin(L);
        var z = N * (1 - e2) * Math.sin(B);

        // Helmert WGS84 → S-JTSK
        var dx = -570.69, dy = -85.69, dz = -462.84;
        var wx = 4.99821 / 3600 * D2R, wy = 1.58676 / 3600 * D2R, wz = 5.2611 / 3600 * D2R;
        var m = -3.543e-6;
        var xn = dx + (1 + m) * (x + wz * y - wy * z);
        var yn = dy + (1 + m) * (-wz * x + y + wx * z);
        var zn = dz + (1 + m) * (wy * x - wx * y + z);

        // Pravoúhlé → zeměpisné na Besselově elipsoidu
        var ab = 6377397.15508, eb2 = 0.006674372230614;
        var p = Math.sqrt(xn * xn + yn * yn);
        var Bb = Math.atan2(zn, p * (1 - eb2));
        for (var i = 0; i < 6; i++) {
            var Nb = ab / Math.sqrt(1 - eb2 * Math.sin(Bb) * Math.sin(Bb));
            Bb = Math.atan2(zn + eb2 * Nb * Math.sin(Bb), p);
        }
        var Lb = Math.atan2(yn, xn);

        return krovak(Bb, Lb);
    }

    /* Křovákovo zobrazení podle EPSG Guidance Note 7-2 (metoda 9819).
       Vrací souřadnice EPSG:5514 (osy se záporným znaménkem). */
    var KR = (function () {
        var a = 6377397.155, e2 = 0.006674372230614, e = Math.sqrt(e2);
        var phiC = 49.5 * D2R, lonO = (24 + 50 / 60) * D2R;
        var alphaC = (30 + 17 / 60 + 17.30311 / 3600) * D2R;
        var phiP = 78.5 * D2R, kP = 0.9999;
        var sC = Math.sin(phiC);
        var A = a * Math.sqrt(1 - e2) / (1 - e2 * sC * sC);
        var B = Math.sqrt(1 + e2 * Math.pow(Math.cos(phiC), 4) / (1 - e2));
        var g0 = Math.asin(sC / B);
        var t0 = Math.tan(Math.PI / 4 + g0 / 2) * Math.pow((1 + e * sC) / (1 - e * sC), e * B / 2)
               / Math.pow(Math.tan(Math.PI / 4 + phiC / 2), B);
        var n = Math.sin(phiP);
        var r0 = kP * A / Math.tan(phiP);
        return { e: e, B: B, t0: t0, n: n, r0: r0, lonO: lonO, alphaC: alphaC, phiP: phiP };
    })();

    function krovak(phi, lam) {
        var s = Math.sin(phi);
        var U = 2 * (Math.atan(KR.t0 * Math.pow(Math.tan(phi / 2 + Math.PI / 4), KR.B)
                / Math.pow((1 + KR.e * s) / (1 - KR.e * s), KR.e * KR.B / 2)) - Math.PI / 4);
        var V = KR.B * (KR.lonO - lam);
        var T = Math.asin(Math.cos(KR.alphaC) * Math.sin(U) + Math.sin(KR.alphaC) * Math.cos(U) * Math.cos(V));
        var Dd = Math.asin(Math.cos(U) * Math.sin(V) / Math.cos(T));
        var theta = KR.n * Dd;
        var r = KR.r0 * Math.pow(Math.tan(Math.PI / 4 + KR.phiP / 2), KR.n)
              / Math.pow(Math.tan(T / 2 + Math.PI / 4), KR.n);
        return { y: -r * Math.sin(theta), x: -r * Math.cos(theta) };
    }

    /**
     * S-JTSK (EPSG:5514) → WGS84 Newtonovou metodou nad toJtsk —
     * po pár krocích je rozdíl pod milimetr, takže stačí jeden vzorec.
     */
    function fromJtsk(y, x) {
        var lat = 49.8, lon = 15.5, h = 1e-6;
        for (var i = 0; i < 12; i++) {
            var f = toJtsk(lat, lon);
            var fy = f.y - y, fx = f.x - x;
            if (Math.abs(fy) < 0.001 && Math.abs(fx) < 0.001) break;
            var a1 = toJtsk(lat + h, lon), a2 = toJtsk(lat, lon + h);
            var j11 = (a1.y - f.y) / h, j12 = (a2.y - f.y) / h;
            var j21 = (a1.x - f.x) / h, j22 = (a2.x - f.x) / h;
            var det = j11 * j22 - j12 * j21;
            if (!det) break;
            lat -= ( j22 * fy - j12 * fx) / det;
            lon -= (-j21 * fy + j11 * fx) / det;
        }
        return { lat: lat, lng: lon };
    }

    /* ===== Formáty ===== */
    var LANG = document.documentElement.lang || "cs";
    function nf(v, dec) {
        return v.toLocaleString(LANG, { minimumFractionDigits: dec, maximumFractionDigits: dec });
    }
    function dms(v, pos, neg) {
        var s = v < 0 ? neg : pos;
        v = Math.abs(v);
        var d = Math.floor(v), mFull = (v - d) * 60, m = Math.floor(mFull);
        var sec = (mFull - m) * 60;
        if (sec >= 59.95) { sec = 0; m += 1; }
        if (m >= 60) { m = 0; d += 1; }
        return d + "°" + m + "′" + sec.toFixed(1) + "″" + s;
    }
    function formats(ll) {
        var j = toJtsk(ll.lat, ll.lng);
        return {
            dec:  ll.lat.toFixed(6) + ", " + ll.lng.toFixed(6),
            dms:  dms(ll.lat, "N", "S") + " " + dms(ll.lng, "E", "W"),
            jtsk: j.y.toFixed(2) + ", " + j.x.toFixed(2),
            jtskShort: nf(j.y, 0) + "  " + nf(j.x, 0)
        };
    }
    function fmtLen(m) {
        return m < 1000 ? nf(m, m < 100 ? 1 : 0) + " m" : nf(m / 1000, m < 10000 ? 2 : 1) + " km";
    }
    function fmtArea(m2) {
        if (m2 < 10000)   return nf(m2, 0) + " m²";
        if (m2 < 1000000) return nf(m2 / 10000, 2) + " ha";
        return nf(m2 / 1000000, 2) + " km²";
    }
    /* Plocha mnohoúhelníku na kouli (stejný vzorec jako Leaflet.draw) */
    function geodesicArea(lls) {
        var R = 6378137, area = 0, n = lls.length;
        for (var i = 0; i < n; i++) {
            var p1 = lls[i], p2 = lls[(i + 1) % n];
            area += (p2.lng - p1.lng) * D2R * (2 + Math.sin(p1.lat * D2R) + Math.sin(p2.lat * D2R));
        }
        return Math.abs(area * R * R / 2);
    }

    function create(map, opts) {
        var i18n = opts.i18n || {};
        var copy = opts.copy;

        /* ===== Souřadnice vlevo dole ===== */
        var coordBox, coordDec, coordJtsk, measBox, measText, btnUndo, btnClear;
        var hasMouse = false;

        function showCoords(ll, isCenter) {
            var f = formats(ll);
            coordDec.textContent  = (isCenter ? "⊕ " : "") + f.dec;
            coordJtsk.textContent = "S-JTSK " + f.jtskShort;
        }

        var Box = L.Control.extend({
            options: { position: "bottomleft" },
            onAdd: function () {
                var div = L.DomUtil.create("div", "gpx-tl-box");
                measBox = L.DomUtil.create("div", "gpx-tl-meas", div);
                measBox.hidden = true;
                measText = L.DomUtil.create("div", "gpx-tl-meas-text", measBox);
                measText.setAttribute("aria-live", "polite");
                var row = L.DomUtil.create("div", "gpx-tl-meas-btns", measBox);
                btnUndo = L.DomUtil.create("button", "gpx-tl-btn", row);
                btnUndo.type = "button";
                btnUndo.textContent = "↶ " + (i18n.undo || "Zpět");
                btnClear = L.DomUtil.create("button", "gpx-tl-btn", row);
                btnClear.type = "button";
                btnClear.textContent = "✕ " + (i18n.clear || "Smazat");
                L.DomEvent.on(btnUndo, "click", function () { points.pop(); drawMeasure(); });
                L.DomEvent.on(btnClear, "click", function () { points = []; drawMeasure(); });

                coordBox = L.DomUtil.create("div", "gpx-tl-coords", div);
                coordBox.title = i18n.coordsHint || "";
                coordDec  = L.DomUtil.create("div", "", coordBox);
                coordJtsk = L.DomUtil.create("div", "gpx-tl-jtsk", coordBox);
                L.DomEvent.disableClickPropagation(div);
                L.DomEvent.disableScrollPropagation(div);
                return div;
            }
        });
        new Box().addTo(map);
        showCoords(map.getCenter(), true);

        map.on("mousemove", function (ev) {
            hasMouse = true;
            showCoords(ev.latlng, false);
            if (measuring && points.length) drawMeasure(ev.latlng);
        });
        map.on("mouseout", function () {
            hasMouse = false;
            showCoords(map.getCenter(), true);
            if (measuring) drawMeasure();
        });
        map.on("move", function () { if (!hasMouse) showCoords(map.getCenter(), true); });

        /* ===== Bublina se souřadnicemi (pravé tlačítko / podržení prstu) ===== */
        map.on("contextmenu", function (ev) {
            var f = formats(ev.latlng);
            var wrap = L.DomUtil.create("div", "gpx-tl-pop");
            [["GPS", f.dec], ["GPS", f.dms], ["S-JTSK", f.jtsk]].forEach(function (r) {
                var row = L.DomUtil.create("div", "gpx-tl-pop-row", wrap);
                L.DomUtil.create("span", "gpx-tl-pop-lbl", row).textContent = r[0];
                L.DomUtil.create("code", "", row).textContent = r[1];
                var b = L.DomUtil.create("button", "gpx-tl-btn", row);
                b.type = "button";
                b.textContent = "⧉";
                b.title = (i18n.copy || "Kopírovat") + ": " + r[1];
                b.setAttribute("aria-label", b.title);
                L.DomEvent.on(b, "click", function () {
                    copy(r[1]).then(function (ok) { b.textContent = ok ? "✓" : "✕"; });
                });
            });
            L.popup({ maxWidth: 360 }).setLatLng(ev.latlng).setContent(wrap).openOn(map);
        });

        /* ===== Měření ===== */
        var measuring = false;
        var points = [];
        var group = L.layerGroup().addTo(map);
        var STYLE = { color: "#d1342f", weight: 3, dashArray: "6 5", interactive: false };

        function drawMeasure(cursor) {
            group.clearLayers();
            var pts = points.slice();
            if (cursor && pts.length) pts.push(cursor);

            if (points.length >= 3) {
                L.polygon(points, { stroke: false, fillColor: "#d1342f", fillOpacity: 0.12, interactive: false }).addTo(group);
            }
            if (pts.length >= 2) L.polyline(pts, STYLE).addTo(group);
            points.forEach(function (p) {
                L.circleMarker(p, { radius: 4, color: "#fff", weight: 2, fillColor: "#d1342f", fillOpacity: 1, interactive: false }).addTo(group);
            });

            var len = 0;
            for (var i = 1; i < pts.length; i++) len += map.distance(pts[i - 1], pts[i]);
            var txt;
            if (!points.length) {
                txt = i18n.measureHint || "Klikáním do mapy přidávejte body";
            } else {
                txt = (i18n.length || "Délka") + ": " + fmtLen(len);
                if (points.length >= 3) txt += " · " + (i18n.area || "Plocha") + ": " + fmtArea(geodesicArea(points));
            }
            measText.textContent = txt;
            btnUndo.disabled = btnClear.disabled = !points.length;
        }

        function setMeasuring(on) {
            measuring = on;
            measBox.hidden = !on;
            btnMeasure.classList.toggle("is-on", on);
            btnMeasure.setAttribute("aria-pressed", String(on));
            map.getContainer().classList.toggle("gpx-tl-measuring", on);
            if (on) map.doubleClickZoom.disable(); else map.doubleClickZoom.enable();
            if (!on) points = [];
            drawMeasure();
        }

        map.on("click", function (ev) {
            if (!measuring) return;
            points.push(ev.latlng);
            drawMeasure(hasMouse ? ev.latlng : null);
        });
        document.addEventListener("keydown", function (ev) {
            if (!measuring || ev.key !== "Escape") return;
            if (document.querySelector(".leaflet-popup")) return;   // Esc nejdřív zavře bublinu
            setMeasuring(false);
        });

        var btnMeasure;
        var Ctl = L.Control.extend({
            options: { position: "topleft" },
            onAdd: function () {
                var bar = L.DomUtil.create("div", "leaflet-bar gpx-cmp-bar");
                btnMeasure = L.DomUtil.create("button", "gpx-cmp-btn", bar);
                btnMeasure.type = "button";
                btnMeasure.textContent = "📏";
                btnMeasure.title = i18n.measure || "Měření vzdálenosti a plochy";
                btnMeasure.setAttribute("aria-label", btnMeasure.title);
                btnMeasure.setAttribute("aria-pressed", "false");
                L.DomEvent.on(btnMeasure, "click", function () { setMeasuring(!measuring); });
                L.DomEvent.disableClickPropagation(bar);
                return bar;
            }
        });
        new Ctl().addTo(map);

        return { toJtsk: toJtsk };
    }

    window.GpxMapTools = { create: create, toJtsk: toJtsk, fromJtsk: fromJtsk };
})();
