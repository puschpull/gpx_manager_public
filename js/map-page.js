/**
 * GPX Manager — stránka Mapa (map.php)
 *
 * Mapa s libovolnou kombinací vrstev. Na rozdíl od L.control.layers, kde
 * je podklad vždy jen jeden, se tu dají zapnout i dva a více podkladů
 * (např. turistická, nad ní historická a ortofoto), každý s vlastním
 * krytím a pořadím.
 *
 * Princip: každá vrstva má vlastní panel Leafletu (map.createPane).
 * Krytí je CSS opacity panelu a pořadí jeho z-index — funguje to stejně
 * pro dlaždice, WMS, skupiny s výběrem období i body (POI, Wikimedia).
 *
 * Vrstvy staví sdílená js/lib/map-factory.js (vč. vypnutí a pořadí
 * z Administrace → Vrstvy map). Stav si pamatuje prohlížeč (localStorage).
 */
(function () {
    "use strict";

    var STORE_KEY = "gpx_map_page";
    var Z_BASE    = 250;   // nad tilePane (200), pod overlayPane (400) s polohou

    function readState() {
        try {
            var s = JSON.parse(localStorage.getItem(STORE_KEY) || "null");
            return (s && typeof s === "object") ? s : {};
        } catch (e) { return {}; }
    }

    function writeState(s) {
        try { localStorage.setItem(STORE_KEY, JSON.stringify(s)); } catch (e) { /* privátní režim */ }
    }

    /**
     * Přesměruje vrstvu (a u skupiny i všechny její budoucí členy) do panelu.
     * Skupiny s výběrem období a body POI/Wikimedia přidávají vrstvy až
     * za běhu, proto se obaluje i jejich addLayer.
     */
    function assignPane(layer, pane) {
        if (layer instanceof L.LayerGroup) {
            layer.options = layer.options || {};
            layer.options.pane = pane;
            layer.eachLayer(function (child) { assignPane(child, pane); });
            if (!layer._gpxPaneWrapped) {
                var origAdd = layer.addLayer;
                layer.addLayer = function (child) {
                    assignPane(child, pane);
                    return origAdd.call(this, child);
                };
                layer._gpxPaneWrapped = true;
            }
        } else {
            layer.options.pane = pane;
        }
    }

    document.addEventListener("DOMContentLoaded", function () {
        var cfg  = window.gpxMapPageData || {};
        var i18n = cfg.i18n || {};
        var keys = cfg.apiKeys || {};
        var F    = window.GpxMapFactory;

        var state = readState();

        // Otevřený odkaz s nastavením (js/map-page-link.js) má přednost před
        // tím, co si pamatuje prohlížeč. Období se musí zapsat před vznikem vrstev.
        var Link = window.GpxMapLink;
        var fromLink = Link ? Link.parse() : null;
        if (fromLink) {
            Link.applyPeriods(fromLink.per);
            if (fromLink.view) state.view = fromLink.view;
            state.cmp = Object.assign({}, state.cmp || {}, fromLink.cmp || { mode: null });
        }
        var map = L.map("gpxMapPage", { fullscreenControl: true });
        if (Array.isArray(state.view) && state.view.length === 3) {
            map.setView([state.view[0], state.view[1]], state.view[2]);
        } else {
            map.setView([49.8, 15.5], 8);
        }

        // Factory přidá výchozí podklad rovnou na mapu — tady o všem
        // rozhoduje panel, proto ho hned zase sundat.
        var made = F.createBaseLayers(keys, map);
        map.removeLayer(made.baseOSM);

        var overlays = Object.assign({}, made.overlayLayers);
        var mapillary = F.createMapillaryOverlay(keys.mapillary || "");
        if (mapillary) overlays[F.layerName("mapillary")] = mapillary;
        overlays[F.layerName("wikimedia")] = F.createWikimediaLayer(map);

        /* entries: všechny vrstvy; active: zapnuté, první = nahoře */
        var entries = [];
        var byName  = {};
        var active  = [];

        // Klíč vrstvy (pro odkaz) podle přeloženého názvu
        var keyByName = {}, nameByKey = {};
        (F.layerKeys ? F.layerKeys() : []).forEach(function (k) {
            var n = F.layerName(k);
            keyByName[n] = k;
            nameByKey[k] = n;
        });

        function register(layers, group) {
            Object.keys(layers).forEach(function (name) {
                if (byName[name]) return;
                var layer = layers[name];
                var paneName = "gpxl" + entries.length;
                var pane = map.createPane(paneName);

                // Výchozí poloprůhlednost některých překryvů (Waymarked,
                // stínování…) se přenese na posuvník, ať se nenásobí s krytím panelu.
                var opacity = 100;
                if (layer instanceof L.GridLayer && typeof layer.options.opacity === "number"
                        && layer.options.opacity < 1) {
                    opacity = Math.round(layer.options.opacity * 100);
                    layer.options.opacity = 1;
                }
                if (layer.options && layer.options.className === "gpx-blend-multiply") {
                    pane.classList.add("gpx-lp-pane-blend");
                }
                assignPane(layer, paneName);

                var e = { name: name, key: keyByName[name] || "", layer: layer, group: group, pane: pane, opacity: opacity };
                entries.push(e);
                byName[name] = e;
            });
        }
        register(made.baseLayers, "base");
        register(overlays, "overlay");

        function save() {
            var c = map.getCenter();
            state.view   = [+c.lat.toFixed(5), +c.lng.toFixed(5), map.getZoom()];
            state.layers = active.map(function (e) { return { n: e.name, o: e.opacity }; });
            writeState(state);
            scheduleHash();
        }

        /* Adresa stránky = aktuální nastavení. Se zpožděním: posuvník krytí
           volá save() mnohokrát za sekundu a prohlížeč replaceState omezuje. */
        var hashTimer = null;
        function linkState() {
            return {
                view: state.view,
                layers: active.map(function (e) { return { k: e.key, o: e.opacity }; }),
                cmp: state.cmp
            };
        }
        function updateHash() {
            clearTimeout(hashTimer);
            if (!Link || !state.view) return;
            Link.update(linkState());
        }
        function scheduleHash() {
            clearTimeout(hashTimer);
            hashTimer = setTimeout(updateHash, 300);
        }
        // Změna období (historické mapy, snímky) přidá do skupiny jinou vrstvu
        map.on("layeradd", scheduleHash);
        // Vložený jiný odkaz do téže karty: replaceState tuhle událost nespouští
        window.addEventListener("hashchange", function () { location.reload(); });

        var compare = null;   // porovnání (js/map-page-compare.js), vzniká níž

        function applyOrder() {
            active.forEach(function (e, i) {
                e.pane.style.zIndex = String(Z_BASE + active.length - i);
            });
            if (compare) compare.refresh();
        }

        function setOpacity(e, value) {
            e.opacity = Math.max(0, Math.min(100, value));
            e.pane.style.opacity = String(e.opacity / 100);
        }

        function turnOn(e, opacity) {
            if (active.indexOf(e) !== -1) return;
            if (typeof opacity === "number") setOpacity(e, opacity);
            else setOpacity(e, e.opacity);
            active.unshift(e);                       // nově zapnutá jde navrch
            applyOrder();
            map.addLayer(e.layer);
            // POI a radar se spouští událostí, kterou jinak posílá L.control.layers
            map.fire("overlayadd", { layer: e.layer, name: e.name });
            if (typeof e.layer.loadPhotos === "function") e.layer.loadPhotos();
        }

        function turnOff(e) {
            var i = active.indexOf(e);
            if (i === -1) return;
            active.splice(i, 1);
            map.removeLayer(e.layer);
            map.fire("overlayremove", { layer: e.layer, name: e.name });
            applyOrder();
        }

        function move(e, delta) {
            var i = active.indexOf(e);
            var j = i + delta;
            if (i === -1 || j < 0 || j >= active.length) return;
            active[i] = active[j];
            active[j] = e;
            applyOrder();
        }

        if (fromLink && fromLink.layers.length) {
            state.layers = fromLink.layers
                .filter(function (l) { return nameByKey[l.k]; })
                .map(function (l) { return { n: nameByKey[l.k], o: l.o }; });
        }

        // Obnovit uložené vrstvy (odspodu, ať horní skončí nahoře)
        var restored = Array.isArray(state.layers) ? state.layers.slice().reverse() : [];
        restored.forEach(function (s) {
            if (s && byName[s.n]) turnOn(byName[s.n], typeof s.o === "number" ? s.o : undefined);
        });
        if (!active.length) {
            var first = entries.filter(function (e) { return e.layer === made.baseOSM; })[0] || entries[0];
            if (first) turnOn(first, 100);
        }

        /* ===== Panel vrstev ===== */
        function el(tag, cls, txt) {
            var n = document.createElement(tag);
            if (cls) n.className = cls;
            if (txt !== undefined) n.textContent = txt;
            return n;
        }

        var panelBody, toggleBtn;

        /* Hlavička panelu: pruh přes celou šířku se šipkou — klik kamkoli
           na něj panel sbalí nebo rozbalí. */
        function updateToggle() {
            var open = !panelBody.hidden;
            toggleBtn.textContent = "";
            toggleBtn.appendChild(el("span", "", "🗂️ " + (i18n.layers || "Vrstvy") + " (" + active.length + ")"));
            toggleBtn.appendChild(el("span", "gpx-lp-chev", open ? "▴" : "▾"));
            toggleBtn.title = open ? (i18n.collapse || "Sbalit") : (i18n.expand || "Rozbalit");
        }

        function renderActive(body) {
            body.appendChild(el("h3", "", i18n.active || "Zapnuté vrstvy"));
            if (!active.length) {
                body.appendChild(el("p", "gpx-lp-empty", i18n.none || ""));
                return;
            }
            var ul = el("ul", "gpx-lp-active");
            active.forEach(function (e, i) {
                var li  = el("li");
                var row = el("div", "gpx-lp-row");
                row.appendChild(el("span", "gpx-lp-name", e.name));

                [["up", "↑", i === 0], ["down", "↓", i === active.length - 1], ["off", "✕", false]]
                    .forEach(function (b) {
                        var btn = el("button", "gpx-lp-btn", b[1]);
                        btn.type = "button";
                        btn.dataset.act  = b[0];
                        btn.dataset.name = e.name;
                        btn.title = i18n[b[0]] || b[0];
                        btn.setAttribute("aria-label", btn.title + ": " + e.name);
                        btn.disabled = b[2];
                        row.appendChild(btn);
                    });
                li.appendChild(row);

                var op = el("label", "gpx-lp-op");
                op.appendChild(el("span", "", i18n.opacity || "Krytí"));
                var range = el("input");
                range.type = "range";
                range.min = "0"; range.max = "100"; range.step = "5";
                range.value = String(e.opacity);
                range.dataset.name = e.name;
                range.setAttribute("aria-label", (i18n.opacity || "Krytí") + ": " + e.name);
                op.appendChild(range);
                op.appendChild(el("output", "", e.opacity + " %"));
                li.appendChild(op);

                ul.appendChild(li);
            });
            body.appendChild(ul);
        }

        /* Seznamy vypnutých vrstev jsou dlouhé — dají se sbalit a panel pak
           ukazuje jen zapnuté vrstvy. Rozbalení si prohlížeč pamatuje. */
        function renderInactive(body, group, title) {
            var list = entries.filter(function (e) { return e.group === group && active.indexOf(e) === -1; });
            if (!list.length) return;
            state.sec = state.sec || {};
            var det = el("details", "gpx-lp-sec");
            det.open = !!state.sec[group];
            var sum = el("summary", "", title + " (" + list.length + ")");
            det.appendChild(sum);
            det.addEventListener("toggle", function () {
                state.sec[group] = det.open;
                save();
            });
            body.appendChild(det);
            var ul = el("ul", "gpx-lp-list");
            list.forEach(function (e) {
                var li  = el("li");
                var lab = el("label");
                var cb  = el("input");
                cb.type = "checkbox";
                cb.dataset.name = e.name;
                lab.appendChild(cb);
                lab.appendChild(el("span", "", e.name));
                li.appendChild(lab);
                ul.appendChild(li);
            });
            det.appendChild(ul);
        }

        function render(focus) {
            panelBody.textContent = "";
            if (Link) {
                var lb = el("button", "gpx-lp-link", "🔗 " + (i18n.copyLink || "Zkopírovat odkaz"));
                lb.type = "button";
                lb.dataset.act = "link";
                lb.title = i18n.linkHint || "";
                panelBody.appendChild(lb);
            }
            renderActive(panelBody);
            if (presets) presets.render(panelBody);
            renderInactive(panelBody, "base", i18n.base || "Podkladové mapy");
            renderInactive(panelBody, "overlay", i18n.overlay || "Překryvné vrstvy");
            updateToggle();

            // Po přestavění vrátit fokus na stejné tlačítko (ovládání klávesnicí)
            if (focus) {
                var sel = '[data-name="' + CSS.escape(focus.name) + '"]'
                        + (focus.act ? '[data-act="' + focus.act + '"]' : "");
                var target = panelBody.querySelector(sel + ":not(:disabled)")
                          || panelBody.querySelector('[data-name="' + CSS.escape(focus.name) + '"]:not(:disabled)');
                if (target) target.focus();
            }
        }

        /* Přestavění až po doběhnutí události: Leaflet u kliku hledá, zda
           cíl leží v ovladači, a u odpojeného tlačítka by to nepoznal. */
        function rerender(focus) { setTimeout(function () { render(focus); }, 0); }

        // Uložené kombinace vrstev (js/map-page-presets.js)
        var presets = (Link && window.GpxMapPresets) ? window.GpxMapPresets.create({
            i18n: i18n,
            state: state,
            save: save,
            hasKey: function (k) { return !!(nameByKey[k] && byName[nameByKey[k]]); },
            currentCombo: function () { return Link.combo(linkState()); },
            apply: function (combo) { save(); Link.applyCombo(state.view, combo); }
        }) : null;

        var Panel = L.Control.extend({
            options: { position: "topright" },
            onAdd: function () {
                var div = L.DomUtil.create("div", "gpx-lp");
                toggleBtn = L.DomUtil.create("button", "gpx-lp-toggle", div);
                toggleBtn.type = "button";
                panelBody = L.DomUtil.create("div", "gpx-lp-body", div);
                panelBody.id = "gpxLayerPanel";
                toggleBtn.setAttribute("aria-controls", panelBody.id);

                var open = typeof state.open === "boolean" ? state.open : window.innerWidth >= 640;
                function setOpen(on) {
                    panelBody.hidden = !on;
                    toggleBtn.setAttribute("aria-expanded", String(on));
                    div.classList.toggle("is-open", on);
                    state.open = on;
                    updateToggle();
                }
                setOpen(open);

                L.DomEvent.on(toggleBtn, "click", function () { setOpen(panelBody.hidden); save(); });

                L.DomEvent.on(panelBody, "click", function (ev) {
                    var btn = ev.target.closest("button[data-act]");
                    if (!btn) return;
                    if (btn.dataset.act === "link") {
                        save();
                        updateHash();
                        Link.copy(location.href).then(function (ok) {
                            btn.textContent = ok ? "✓ " + (i18n.linkCopied || "Odkaz zkopírován")
                                                 : (i18n.linkFailed || "Zkopírujte adresu z adresního řádku");
                            setTimeout(function () {
                                btn.textContent = "🔗 " + (i18n.copyLink || "Zkopírovat odkaz");
                            }, 2500);
                        });
                        return;
                    }
                    var e = byName[btn.dataset.name];
                    if (!e) return;
                    if (btn.dataset.act === "up")   move(e, -1);
                    if (btn.dataset.act === "down") move(e, 1);
                    if (btn.dataset.act === "off")  turnOff(e);
                    save();
                    rerender(btn.dataset.act === "off" ? null : { name: e.name, act: btn.dataset.act });
                });

                L.DomEvent.on(panelBody, "change", function (ev) {
                    var cb = ev.target;
                    if (cb.type !== "checkbox" || !byName[cb.dataset.name]) return;
                    if (cb.checked) turnOn(byName[cb.dataset.name]);
                    save();
                    rerender({ name: cb.dataset.name, act: "off" });
                });

                L.DomEvent.on(panelBody, "input", function (ev) {
                    var r = ev.target;
                    if (r.type !== "range" || !byName[r.dataset.name]) return;
                    setOpacity(byName[r.dataset.name], parseInt(r.value, 10) || 0);
                    var out = r.parentNode.querySelector("output");
                    if (out) out.textContent = r.value + " %";
                    save();
                });

                L.DomEvent.disableClickPropagation(div);
                L.DomEvent.disableScrollPropagation(div);
                render();
                return div;
            }
        });

        new Panel().addTo(map);
        if (window.GpxMapCompare) {
            compare = window.GpxMapCompare.create(map, {
                i18n: i18n,
                state: state,
                save: save,
                getActive: function () { return active; }
            });
        }
        if (F.createLocateControl) F.createLocateControl(map);
        if (window.GpxMapTools) {
            window.GpxMapTools.create(map, {
                i18n: i18n,
                copy: Link ? Link.copy : function () { return Promise.resolve(false); }
            });
        }

        map.on("moveend", save);
        save();
    });
})();
