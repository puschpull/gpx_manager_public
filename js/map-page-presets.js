/**
 * GPX Manager — stránka Mapa: uložené kombinace vrstev.
 *
 * Kombinace = zapnuté vrstvy, jejich krytí a pořadí, porovnání a období,
 * bez výřezu mapy — po přepnutí zůstane mapa na stejném místě. Ukládá se
 * ve tvaru odkazu (js/map-page-link.js) do localStorage, takže platí jen
 * v tomto prohlížeči. Několik kombinací je připravených předem.
 */
(function () {
    "use strict";

    var STORE_KEY = "gpx_map_presets";
    var MAX_NAME  = 40;

    /* Připravené kombinace: klíče vrstev viz map-factory.js */
    var BUILTIN = [
        { id: "hist",   name: "Historická mapa a dnešek",
          combo: "layers=hist_maps:100,mapy_out:100&cmp=swipe:50&per=hist_maps:Military2", need: ["hist_maps", "mapy_out"] },
        { id: "ortho",  name: "Letecké snímky 2001–2003 a dnes",
          combo: "layers=cuzk_ortho:100,mapy_aerial:100&cmp=swipe:50&per=cuzk_ortho:2001-2003", need: ["cuzk_ortho", "mapy_aerial"] },
        { id: "relief", name: "Turistická se stínováním reliéfu",
          combo: "layers=relief_blend:100,mapy_out:100", need: ["relief_blend", "mapy_out"] },
        { id: "cad",    name: "Katastr nad leteckou mapou",
          combo: "layers=cadastre:100,mapy_aerial:100", need: ["cadastre", "mapy_aerial"] }
    ];

    function readList() {
        try {
            var l = JSON.parse(localStorage.getItem(STORE_KEY) || "[]");
            return Array.isArray(l) ? l.filter(function (p) {
                return p && typeof p.name === "string" && typeof p.combo === "string";
            }) : [];
        } catch (e) { return []; }
    }
    function writeList(l) {
        try { localStorage.setItem(STORE_KEY, JSON.stringify(l)); return true; } catch (e) { return false; }
    }

    function el(tag, cls, txt) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (txt !== undefined) n.textContent = txt;
        return n;
    }

    /**
     * @param {{ i18n: object, state: object, save: function(),
     *           hasKey: function(string): boolean,
     *           currentCombo: function(): string,
     *           apply: function(string) }} opts
     */
    function create(opts) {
        var i18n  = opts.i18n || {};
        var state = opts.state;

        function builtins() {
            return BUILTIN.filter(function (b) { return b.need.every(opts.hasKey); });
        }

        /* Seznam se přestavuje až po doběhnutí kliku — Leaflet u odpojeného
           tlačítka nepozná, že klik patří panelu (stejně jako v map-page.js). */
        function fill(ul) {
            ul.textContent = "";
            var cur = opts.currentCombo();

            builtins().forEach(function (b) {
                ul.appendChild(item(i18n["pre_" + b.id] || b.name, b.combo, cur, null));
            });
            readList().forEach(function (p, idx) {
                ul.appendChild(item(p.name, p.combo, cur, idx));
            });
        }

        function item(name, combo, cur, idx) {
            var li  = el("li", combo === cur ? "is-current" : "");
            var btn = el("button", "gpx-pr-apply", name);
            btn.type = "button";
            if (combo === cur) btn.setAttribute("aria-current", "true");
            btn.addEventListener("click", function () { opts.apply(combo); });
            li.appendChild(btn);
            if (idx !== null) {
                var del = el("button", "gpx-lp-btn gpx-pr-del", "✕");
                del.type = "button";
                del.title = (i18n.preDel || "Smazat kombinaci") + ": " + name;
                del.setAttribute("aria-label", del.title);
                del.addEventListener("click", function () {
                    var l = readList();
                    l.splice(idx, 1);
                    writeList(l);
                    var ul = li.parentNode;
                    setTimeout(function () { fill(ul); }, 0);
                });
                li.appendChild(del);
            }
            return li;
        }

        /** Sekce do panelu vrstev (volá se při každém přestavění panelu). */
        function render(body) {
            state.sec = state.sec || {};
            var det = el("details", "gpx-lp-sec gpx-pr");
            det.open = !!state.sec.presets;
            det.appendChild(el("summary", "", i18n.presets || "Uložené kombinace"));
            det.addEventListener("toggle", function () {
                state.sec.presets = det.open;
                opts.save();
            });

            var ul = el("ul", "gpx-pr-list");
            det.appendChild(ul);
            fill(ul);

            var form = el("form", "gpx-pr-form");
            var input = el("input");
            input.type = "text";
            input.maxLength = MAX_NAME;
            input.placeholder = i18n.preName || "Název kombinace";
            input.setAttribute("aria-label", input.placeholder);
            var sv = el("button", "gpx-tl-btn", i18n.preSave || "Uložit současnou");
            sv.type = "submit";
            form.appendChild(input);
            form.appendChild(sv);
            form.addEventListener("submit", function (ev) {
                ev.preventDefault();
                var name = input.value.trim().slice(0, MAX_NAME);
                if (!name) { input.focus(); return; }
                var l = readList();
                var combo = opts.currentCombo();
                // Stejný název přepíše starou kombinaci
                l = l.filter(function (p) { return p.name !== name; });
                l.push({ name: name, combo: combo });
                writeList(l);
                input.value = "";
                setTimeout(function () { fill(ul); }, 0);
            });
            det.appendChild(form);
            det.appendChild(el("p", "gpx-pr-note", i18n.preLocal || "Ukládá se jen v tomto prohlížeči."));
            body.appendChild(det);
        }

        return { render: render };
    }

    window.GpxMapPresets = { create: create };
})();
