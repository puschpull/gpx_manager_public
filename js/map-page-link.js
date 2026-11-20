/**
 * GPX Manager — stránka Mapa: nastavení mapy v adrese stránky.
 *
 * Adresa se průběžně přepisuje (history.replaceState), takže jde kdykoli
 * uložit do záložek nebo poslat. Kdo ji otevře, uvidí stejný výřez,
 * vrstvy, krytí, porovnání i zvolené období historických map a snímků.
 *
 * Tvar (za #, server ho nevidí):
 *   map=15/50.08700/14.42000
 *   &layers=hist_maps:100,mapy_aerial:60      (shora dolů, klíč:krytí)
 *   &cmp=swipe:40  |  cmp=lens:120            (posuvník v %, lupa poloměr v px)
 *   &per=hist_maps:<id>,cuzk_ortho:<id>       (zvolené období)
 * Vrstvy jsou podle klíčů, ne podle názvů — ty se liší jazykem.
 */
(function () {
    "use strict";

    /* Vrstvy s výběrem období a klíč, pod kterým si období pamatuje map-factory.js */
    var PERIOD_STORE = {
        cuzk_ortho: "gpx_ortho_period",
        hist_maps:  "gpx_hist_map",
        relief:     "gpx_relief_variant"
    };

    function num(v, min, max) {
        var n = parseFloat(v);
        if (!isFinite(n)) return null;
        return Math.max(min, Math.min(max, n));
    }

    /** Přečte nastavení z adresy; bez platného odkazu vrací null. */
    function parse() {
        var h = (location.hash || "").replace(/^#/, "");
        if (h.indexOf("map=") === -1 && h.indexOf("layers=") === -1) return null;

        var out = { view: null, layers: [], cmp: null, per: {} };
        h.split("&").forEach(function (part) {
            var i = part.indexOf("=");
            if (i < 1) return;
            var k = part.slice(0, i);
            var v = part.slice(i + 1);
            try { v = decodeURIComponent(v); } catch (e) { return; }

            if (k === "map") {
                var m = v.split("/");
                var z = num(m[0], 1, 20), lat = num(m[1], -85, 85), lng = num(m[2], -180, 180);
                if (z !== null && lat !== null && lng !== null) out.view = [lat, lng, Math.round(z)];
            } else if (k === "layers") {
                v.split(",").forEach(function (it) {
                    var kv = it.split(":");
                    if (!/^[a-z0-9_]+$/.test(kv[0] || "")) return;
                    var o = num(kv[1], 0, 100);
                    out.layers.push({ k: kv[0], o: o === null ? 100 : Math.round(o) });
                });
            } else if (k === "cmp") {
                var c = v.split(":");
                if (c[0] === "swipe") out.cmp = { mode: "swipe", x: (num(c[1], 2, 98) || 50) / 100 };
                if (c[0] === "lens")  out.cmp = { mode: "lens",  r: num(c[1], 40, 300) || 120 };
            } else if (k === "per") {
                v.split(",").forEach(function (it) {
                    var j = it.indexOf(":");
                    var key = it.slice(0, j);
                    if (j > 0 && PERIOD_STORE[key]) out.per[key] = it.slice(j + 1);
                });
            }
        });
        return out;
    }

    /**
     * Uloží období z odkazu tam, odkud si je map-factory.js přečte —
     * proto se musí volat před vytvořením vrstev.
     */
    function applyPeriods(per) {
        Object.keys(per || {}).forEach(function (key) {
            try { localStorage.setItem(PERIOD_STORE[key], per[key]); } catch (e) { /* privátní režim */ }
        });
    }

    /**
     * @param {{ view: number[], layers: Array<{k: string, o: number}>,
     *           cmp: {mode?: string, x?: number, r?: number} }} s
     */
    function build(s) {
        var parts = [];
        if (s.view && !s.noView) parts.push("map=" + s.view[2] + "/" + s.view[0].toFixed(5) + "/" + s.view[1].toFixed(5));
        var ls = s.layers.filter(function (l) { return l.k; });
        if (ls.length) parts.push("layers=" + ls.map(function (l) { return l.k + ":" + l.o; }).join(","));

        var c = s.cmp || {};
        if (c.mode === "swipe") parts.push("cmp=swipe:" + Math.round((typeof c.x === "number" ? c.x : 0.5) * 100));
        if (c.mode === "lens")  parts.push("cmp=lens:" + (c.r || 120));

        var per = [];
        ls.forEach(function (l) {
            if (!PERIOD_STORE[l.k]) return;
            var id = null;
            try { id = localStorage.getItem(PERIOD_STORE[l.k]); } catch (e) { /* nic */ }
            if (id) per.push(l.k + ":" + encodeURIComponent(id));
        });
        if (per.length) parts.push("per=" + per.join(","));
        return "#" + parts.join("&");
    }

    function update(s) {
        var hash = build(s);
        if (hash === location.hash) return;
        try { history.replaceState(history.state, "", location.pathname + location.search + hash); } catch (e) { /* nic */ }
    }

    /** Zkopíruje text do schránky; na http (bez Clipboard API) náhradní cestou. */
    function copy(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return fallback(); });
        }
        return Promise.resolve(fallback());

        function fallback() {
            var ta = document.createElement("textarea");
            ta.value = text;
            ta.setAttribute("readonly", "");
            ta.style.position = "fixed";
            ta.style.opacity = "0";
            document.body.appendChild(ta);
            ta.select();
            var ok = false;
            try { ok = document.execCommand("copy"); } catch (e) { ok = false; }
            document.body.removeChild(ta);
            return ok;
        }
    }

    /** Kombinace vrstev bez výřezu mapy (pro uložené kombinace). */
    function combo(s) {
        return build(Object.assign({}, s, { noView: true })).replace(/^#/, "");
    }

    /** Přepne na kombinaci a zachová výřez — stránka se znovu načte (hashchange). */
    function applyCombo(view, comboStr) {
        var m = "map=" + view[2] + "/" + view[0].toFixed(5) + "/" + view[1].toFixed(5);
        location.hash = m + (comboStr ? "&" + comboStr : "");
    }

    window.GpxMapLink = { parse: parse, applyPeriods: applyPeriods, update: update, copy: copy,
                          combo: combo, applyCombo: applyCombo };
})();
