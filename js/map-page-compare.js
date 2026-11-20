/**
 * GPX Manager — stránka Mapa: porovnání horní vrstvy s vrstvami pod ní.
 *
 * Dva režimy (tlačítka vlevo pod přiblížením):
 *  - posuvník: horní vrstva je vidět jen vlevo od svislé čáry, vpravo
 *    jsou vrstvy pod ní; čára se táhne myší, prstem nebo šipkami,
 *  - lupa: horní vrstva je vidět jen v kruhu kolem kurzoru.
 *
 * Ořez je CSS clip-path na panelu horní vrstvy. Panely leží v mapPane,
 * který Leaflet posouvá, proto se souřadnice přepočítávají při každém
 * pohybu mapy (containerPointToLayerPoint).
 */
(function () {
    "use strict";

    var LENS_MIN = 40, LENS_MAX = 300, LENS_DEF = 120;   // poloměr lupy v px

    function create(map, opts) {
        var i18n   = opts.i18n || {};
        var state  = opts.state;
        var cmp    = state.cmp && typeof state.cmp === "object" ? state.cmp : {};
        state.cmp  = cmp;
        var mode   = (cmp.mode === "swipe" || cmp.mode === "lens") ? cmp.mode : null;
        var frac   = typeof cmp.x === "number" ? Math.max(0.02, Math.min(0.98, cmp.x)) : 0.5;
        var lensR  = typeof cmp.r === "number" ? Math.max(LENS_MIN, Math.min(LENS_MAX, cmp.r)) : LENS_DEF;
        var lensPt = null;                 // poloha kurzoru (bod kontejneru)
        var clipped = null;                // panel, který je teď oříznutý

        var container = map.getContainer();

        /* --- svislá čára posuvníku --- */
        var divider = L.DomUtil.create("div", "gpx-cmp-divider", container);
        divider.setAttribute("role", "slider");
        divider.setAttribute("tabindex", "0");
        divider.setAttribute("aria-valuemin", "0");
        divider.setAttribute("aria-valuemax", "100");
        divider.setAttribute("aria-label", i18n.swipe || "Porovnávací posuvník");
        var handle = L.DomUtil.create("div", "gpx-cmp-handle", divider);
        handle.textContent = "◀ ▶";
        var label = L.DomUtil.create("div", "gpx-cmp-label", divider);
        L.DomEvent.disableClickPropagation(divider);
        L.DomEvent.disableScrollPropagation(divider);

        /* --- kroužek lupy (jen vizuál, kliky propouští) --- */
        var ring = L.DomUtil.create("div", "gpx-cmp-ring", container);
        function sizeRing() { ring.style.width = ring.style.height = (lensR * 2) + "px"; }
        sizeRing();

        function topEntry() {
            var a = opts.getActive();
            return a.length >= 2 ? a[0] : null;
        }

        function clear() {
            if (clipped) { clipped.style.clipPath = ""; clipped = null; }
        }

        function refresh() {
            var top = topEntry();
            if (clipped && (!top || clipped !== top.pane || !mode)) clear();

            divider.hidden = !(mode === "swipe" && top);
            ring.hidden    = !(mode === "lens" && top && lensPt);
            updateButtons(!!top);
            if (!mode || !top) return;

            var size = map.getSize();
            var o = map.containerPointToLayerPoint([0, 0]);   // = posun mapPane
            var css;
            if (mode === "swipe") {
                var xs = Math.round(size.x * frac);
                var x  = o.x + xs;
                css = "polygon(" + o.x + "px " + o.y + "px, " + x + "px " + o.y + "px, "
                    + x + "px " + (o.y + size.y) + "px, " + o.x + "px " + (o.y + size.y) + "px)";
                divider.style.left = xs + "px";
                divider.setAttribute("aria-valuenow", String(Math.round(frac * 100)));
                label.textContent = "◀ " + top.name;
            } else {
                var p = lensPt || L.point(size.x / 2, size.y / 2);
                css = "circle(" + lensR + "px at " + (o.x + p.x) + "px " + (o.y + p.y) + "px)";
                ring.style.left = (p.x - lensR) + "px";
                ring.style.top  = (p.y - lensR) + "px";
            }
            top.pane.style.clipPath = css;
            clipped = top.pane;
        }

        /* --- tažení čáry --- */
        var dragging = false;
        function setFromClientX(clientX) {
            var r = container.getBoundingClientRect();
            frac = Math.max(0.02, Math.min(0.98, (clientX - r.left) / r.width));
            cmp.x = +frac.toFixed(3);
            refresh();
        }
        divider.addEventListener("pointerdown", function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            dragging = true;
            divider.setPointerCapture(ev.pointerId);
            divider.classList.add("is-drag");
        });
        divider.addEventListener("pointermove", function (ev) {
            if (dragging) setFromClientX(ev.clientX);
        });
        function endDrag() {
            if (!dragging) return;
            dragging = false;
            divider.classList.remove("is-drag");
            opts.save();
        }
        divider.addEventListener("pointerup", endDrag);
        divider.addEventListener("pointercancel", endDrag);
        divider.addEventListener("keydown", function (ev) {
            var step = ev.shiftKey ? 0.1 : 0.02;
            if (ev.key === "ArrowLeft")       frac -= step;
            else if (ev.key === "ArrowRight") frac += step;
            else if (ev.key === "Home")       frac = 0.02;
            else if (ev.key === "End")        frac = 0.98;
            else return;
            ev.preventDefault();
            ev.stopPropagation();          // šipky jinak posouvají mapu
            frac = Math.max(0.02, Math.min(0.98, frac));
            cmp.x = +frac.toFixed(3);
            refresh();
            opts.save();
        });

        /* --- lupa sleduje kurzor --- */
        map.on("mousemove", function (ev) {
            if (mode !== "lens") return;
            lensPt = ev.containerPoint;
            refresh();
        });
        // Po odjetí kurzoru z mapy lupa zůstane, kde byla.
        map.on("move zoomend viewreset resize", refresh);

        /* --- tlačítka --- */
        var btnSwipe, btnLens, sizeBox, sizeRange;
        function updateButtons(enabled) {
            if (!btnSwipe) return;
            sizeBox.hidden = !(mode === "lens" && enabled);
            [[btnSwipe, "swipe"], [btnLens, "lens"]].forEach(function (b) {
                var on = mode === b[1];
                b[0].classList.toggle("is-on", on);
                b[0].setAttribute("aria-pressed", String(on));
                b[0].classList.toggle("is-disabled", !enabled);
                var base = b[1] === "swipe" ? (i18n.swipe || "Porovnávací posuvník") : (i18n.lens || "Lupa");
                b[0].title = enabled ? base : base + " — " + (i18n.cmpNeed || "zapněte aspoň dvě vrstvy");
            });
        }
        function setMode(m) {
            mode = (mode === m) ? null : m;
            cmp.mode = mode;
            if (mode === "lens" && !lensPt) {
                var s = map.getSize();
                lensPt = L.point(s.x / 2, s.y / 2);
            }
            refresh();
            opts.save();
        }

        var Ctl = L.Control.extend({
            options: { position: "topleft" },
            onAdd: function () {
                var bar = L.DomUtil.create("div", "leaflet-bar gpx-cmp-bar");
                btnSwipe = L.DomUtil.create("button", "gpx-cmp-btn", bar);
                btnSwipe.type = "button";
                btnSwipe.textContent = "◧";
                btnSwipe.setAttribute("aria-label", i18n.swipe || "Porovnávací posuvník");
                btnLens = L.DomUtil.create("button", "gpx-cmp-btn", bar);
                btnLens.type = "button";
                btnLens.textContent = "◎";
                btnLens.setAttribute("aria-label", i18n.lens || "Lupa");
                L.DomEvent.on(btnSwipe, "click", function () { setMode("swipe"); });
                L.DomEvent.on(btnLens, "click", function () { setMode("lens"); });
                // Velikost lupy — ukazuje se jen v režimu lupy, vpravo od tlačítek
                sizeBox = L.DomUtil.create("label", "gpx-cmp-size", bar);
                L.DomUtil.create("span", "", sizeBox).textContent = i18n.lensSize || "Velikost lupy";
                var range = sizeRange = L.DomUtil.create("input", "", sizeBox);
                range.type = "range";
                range.min = String(LENS_MIN); range.max = String(LENS_MAX); range.step = "10";
                range.value = String(lensR);
                L.DomEvent.on(range, "input", function () {
                    lensR = parseInt(range.value, 10) || LENS_DEF;
                    cmp.r = lensR;
                    sizeRing();
                    refresh();
                });
                L.DomEvent.on(range, "change", function () { opts.save(); });

                L.DomEvent.disableClickPropagation(bar);
                L.DomEvent.disableScrollPropagation(bar);
                return bar;
            }
        });
        new Ctl().addTo(map);

        refresh();
        return {
            refresh: refresh,
            /** Zapne/vypne režim (klávesové zkratky). */
            toggle: setMode,
            mode: function () { return mode; },
            /** Zvětší/zmenší lupu o delta px; vrací nový poloměr. */
            lensBy: function (delta) {
                lensR = Math.max(LENS_MIN, Math.min(LENS_MAX, lensR + delta));
                cmp.r = lensR;
                if (sizeRange) sizeRange.value = String(lensR);
                sizeRing();
                refresh();
                opts.save();
                return lensR;
            }
        };
    }

    window.GpxMapCompare = { create: create };
})();
