/**
 * GPX Manager — stránka Mapa: klávesové zkratky a jejich nápověda.
 *
 * Písmena jsou zvolená tak, aby byla na české i anglické klávesnici na
 * stejném místě (žádné Y/Z, závorky ani znaky z horní řady čísel).
 * Zkratky nefungují, když se píše do políčka (hledání, název kombinace,
 * formulář značky) nebo je stisknutý Ctrl/Alt/Cmd — ty patří prohlížeči.
 * Šipky a +/− dál ovládá Leaflet (posun a přiblížení mapy).
 */
(function () {
    "use strict";

    function el(tag, cls, txt) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (txt !== undefined) n.textContent = txt;
        return n;
    }

    function isTyping(t) {
        if (!t || !t.tagName) return false;
        var tag = t.tagName;
        return tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT" || t.isContentEditable;
    }

    /**
     * @param {L.Map} map
     * @param {{ i18n: object, actions: object }} opts
     *   actions: { panel, search, swipe, lens, lensBigger, lensSmaller, measure,
     *              swap, opacityUp, opacityDown, copyLink } — každá vrací
     *              text pro krátké oznámení, Promise s textem, nebo nic
     */
    function create(map, opts) {
        var i18n = opts.i18n || {};
        var A    = opts.actions || {};

        /* [klávesa, akce, popis] — pořadí = pořadí v nápovědě */
        var KEYS = [
            ["v", "panel",       i18n.panel   || "Panel vrstev sbalit / rozbalit"],
            ["h", "search",      i18n.search  || "Hledat místo"],
            ["q", "opacityDown", i18n.opDown  || "Horní vrstva průhlednější (−10 %)"],
            ["w", "opacityUp",   i18n.opUp    || "Horní vrstva sytější (+10 %)"],
            ["x", "swap",        i18n.swap    || "Prohodit horní dvě vrstvy"],
            ["p", "swipe",       i18n.swipe   || "Porovnávací posuvník"],
            ["l", "lens",        i18n.lens    || "Lupa"],
            ["a", "lensSmaller", i18n.lensS   || "Lupa menší"],
            ["s", "lensBigger",  i18n.lensB   || "Lupa větší"],
            ["m", "measure",     i18n.measure || "Měření vzdálenosti a plochy"],
            ["k", "copyLink",    i18n.link    || "Zkopírovat odkaz"],
            ["?", "help",        i18n.help    || "Tato nápověda"]
        ];
        var byKey = {};
        KEYS.forEach(function (k) { byKey[k[0]] = k[1]; });

        var container = map.getContainer();

        /* --- krátké oznámení dole uprostřed --- */
        var toast = el("div", "gpx-key-toast");
        toast.setAttribute("role", "status");
        toast.hidden = true;
        container.appendChild(toast);
        var toastTimer = null;
        function say(text) {
            if (!text) return;
            toast.textContent = text;
            toast.hidden = false;
            clearTimeout(toastTimer);
            toastTimer = setTimeout(function () { toast.hidden = true; }, 1600);
        }

        /* --- nápověda --- */
        var help = el("div", "gpx-key-help");
        help.setAttribute("role", "dialog");
        help.setAttribute("aria-modal", "false");
        help.setAttribute("aria-label", i18n.title || "Klávesové zkratky");
        help.hidden = true;
        var head = el("div", "gpx-key-head");
        head.appendChild(el("strong", "", "⌨️ " + (i18n.title || "Klávesové zkratky")));
        var close = el("button", "gpx-lp-btn", "✕");
        close.type = "button";
        close.setAttribute("aria-label", i18n.close || "Zavřít");
        head.appendChild(close);
        help.appendChild(head);
        var dl = el("dl", "gpx-key-list");
        KEYS.forEach(function (k) {
            var dt = el("dt");
            dt.appendChild(el("kbd", "", k[0].toUpperCase()));
            dl.appendChild(dt);
            dl.appendChild(el("dd", "", k[2]));
        });
        [["← ↑ → ↓", i18n.arrows || "Posun mapy (po kliknutí do mapy)"],
         ["+ −",     i18n.zoom   || "Přiblížit / oddálit"],
         ["Esc",     i18n.esc    || "Zavřít bublinu, ukončit měření"]].forEach(function (k) {
            var dt = el("dt");
            dt.appendChild(el("kbd", "", k[0]));
            dl.appendChild(dt);
            dl.appendChild(el("dd", "", k[1]));
        });
        help.appendChild(dl);
        help.appendChild(el("p", "gpx-key-note", i18n.note || "Zkratky nefungují při psaní do políčka."));
        container.appendChild(help);
        L.DomEvent.disableClickPropagation(help);
        L.DomEvent.disableScrollPropagation(help);

        var lastFocus = null;
        function toggleHelp(show) {
            var on = typeof show === "boolean" ? show : help.hidden;
            if (on) lastFocus = document.activeElement;
            help.hidden = !on;
            if (on) close.focus();
            else if (lastFocus && lastFocus.focus) lastFocus.focus();
        }
        close.addEventListener("click", function () { toggleHelp(false); });

        /* --- tlačítko ⌨️ pod ostatními vlevo --- */
        var Ctl = L.Control.extend({
            options: { position: "topleft" },
            onAdd: function () {
                var bar = L.DomUtil.create("div", "leaflet-bar gpx-cmp-bar");
                var b = L.DomUtil.create("button", "gpx-cmp-btn", bar);
                b.type = "button";
                b.textContent = "⌨️";
                b.title = (i18n.title || "Klávesové zkratky") + " (?)";
                b.setAttribute("aria-label", b.title);
                L.DomEvent.on(b, "click", function () { toggleHelp(); });
                L.DomEvent.disableClickPropagation(bar);
                return bar;
            }
        });
        new Ctl().addTo(map);

        document.addEventListener("keydown", function (ev) {
            if (ev.ctrlKey || ev.altKey || ev.metaKey) return;
            if (ev.key === "Escape" && !help.hidden) { toggleHelp(false); ev.preventDefault(); return; }
            if (isTyping(ev.target)) return;
            var key = ev.key && ev.key.length === 1 ? ev.key.toLowerCase() : "";
            var act = byKey[key];
            if (!act) return;
            ev.preventDefault();
            if (act === "help") { toggleHelp(); return; }
            if (typeof A[act] !== "function") return;
            var r = A[act]();
            if (r && typeof r.then === "function") r.then(say); else say(r);
        });
    }

    window.GpxMapKeys = { create: create };
})();
