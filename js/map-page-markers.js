/**
 * GPX Manager — stránka Mapa: značky s poznámkou (vrstva „Moje značky“).
 *
 * Značky jsou v databázi (map_markers, api/markers/*). Admin je přidává
 * z bubliny se souřadnicemi (pravé tlačítko / podržení prstu → „Přidat
 * značku“), upravuje, přesouvá tažením a maže; návštěvník vidí jen značky
 * označené „ukázat i návštěvníkům“. Ze značky jde naplánovat cesta v Plánovači.
 */
(function () {
    "use strict";

    var SYMBOLS = {
        pin: "📍", star: "⭐", question: "❓", ruin: "🏚️",
        water: "💧", cross: "✝️", camera: "📷", flag: "🚩"
    };

    function el(tag, cls, txt) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (txt !== undefined) n.textContent = txt;
        return n;
    }

    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute("content") : "";
    }

    function post(url, data) {
        var fd = new FormData();
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        fd.append("_csrf_token", csrf());
        return fetch(url, { method: "POST", body: fd, credentials: "same-origin" })
            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); });
    }

    /**
     * @param {L.Map} map
     * @param {{ i18n: object, isAdmin: boolean, plannerVisible: boolean,
     *           ensureOn: function() }} opts  ensureOn = zapnout vrstvu v panelu
     * @returns {{ layer: L.LayerGroup, startAdd: function(L.LatLng) }}
     */
    function create(map, opts) {
        var i18n  = opts.i18n || {};
        var group = L.layerGroup();
        var byId  = {};
        var loaded = false;

        function icon(sym) {
            return L.divIcon({
                className: "gpx-mk-icon",
                html: '<span class="gpx-mk-sym">' + (SYMBOLS[sym] || SYMBOLS.pin) + "</span>",
                iconSize: [30, 30], iconAnchor: [15, 28], popupAnchor: [0, -26]
            });
        }

        function load() {
            if (loaded) return;
            loaded = true;
            fetch("api/markers/list.php", { credentials: "same-origin" })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) { if (d && d.markers) d.markers.forEach(put); })
                .catch(function () { loaded = false; });
        }
        group.on("add", load);

        /* Vloží nebo aktualizuje značku na mapě */
        function put(d) {
            var m = byId[d.id];
            if (!m) {
                m = L.marker([d.lat, d.lon], { icon: icon(d.symbol), draggable: !!opts.isAdmin, keyboard: true });
                m.on("dragend", function () {
                    var p = m.getLatLng();
                    var cur = m._gpxData;
                    save(Object.assign({}, cur, { lat: p.lat, lon: p.lng }));
                });
                byId[d.id] = m;
                group.addLayer(m);
            } else {
                m.setLatLng([d.lat, d.lon]);
                m.setIcon(icon(d.symbol));
            }
            m._gpxData = d;
            m.options.title = d.title;
            if (m._icon) m._icon.title = d.title;
            m.bindPopup(function () { return viewBox(d.id); }, { maxWidth: 300, minWidth: 220 });
            return m;
        }

        function viewBox(id) {
            var d = byId[id]._gpxData;
            var box = el("div", "gpx-mk-pop");
            box.appendChild(el("strong", "gpx-mk-title", (SYMBOLS[d.symbol] || "") + " " + d.title));
            if (d.note) box.appendChild(el("div", "gpx-mk-note", d.note));
            box.appendChild(el("div", "gpx-mk-coords", d.lat.toFixed(6) + ", " + d.lon.toFixed(6)));
            if (opts.isAdmin && d.isPublic) box.appendChild(el("div", "gpx-mk-pub", "👁 " + (i18n.public || "Vidí i návštěvníci")));

            var row = el("div", "gpx-mk-btns");
            if (opts.plannerVisible) {
                var a = el("a", "gpx-tl-btn", "🧭 " + (i18n.toPlanner || "Do plánovače"));
                a.href = "planner.php#pt=" + d.lat.toFixed(6) + "," + d.lon.toFixed(6);
                row.appendChild(a);
            }
            if (opts.isAdmin) {
                var ed = el("button", "gpx-tl-btn", "✏️ " + (i18n.edit || "Upravit"));
                ed.type = "button";
                ed.addEventListener("click", function () { openForm(d, byId[id]); });
                row.appendChild(ed);
                var del = el("button", "gpx-tl-btn", "🗑️ " + (i18n.del || "Smazat"));
                del.type = "button";
                del.addEventListener("click", function () {
                    if (!window.confirm((i18n.delConfirm || "Smazat značku?") + "\n" + d.title)) return;
                    post("api/markers/delete.php", { id: d.id }).then(function (r) {
                        if (!r.ok) { window.alert(i18n.failed || "Uložení se nepovedlo."); return; }
                        map.closePopup();
                        group.removeLayer(byId[id]);
                        delete byId[id];
                    });
                });
                row.appendChild(del);
            }
            if (row.childNodes.length) box.appendChild(row);
            return box;
        }

        function save(d) {
            return post("api/markers/save.php", {
                id: d.id || 0, lat: d.lat, lon: d.lon, title: d.title,
                note: d.note || "", symbol: d.symbol || "pin", is_public: d.isPublic ? "1" : "0"
            }).then(function (r) {
                if (!r.ok || !r.marker) { window.alert(i18n.failed || "Uložení se nepovedlo."); return null; }
                return put(r.marker);
            });
        }

        /* Formulář nové nebo upravované značky v bublině */
        function openForm(d, marker) {
            var form = el("form", "gpx-mk-form");
            var t = el("input");
            t.type = "text"; t.maxLength = 120; t.required = true;
            t.value = d.title || "";
            t.placeholder = i18n.title || "Název";
            t.setAttribute("aria-label", t.placeholder);
            var n = el("textarea");
            n.rows = 4; n.maxLength = 5000;
            n.value = d.note || "";
            n.placeholder = i18n.note || "Poznámka";
            n.setAttribute("aria-label", n.placeholder);

            var syms = el("div", "gpx-mk-syms");
            syms.setAttribute("role", "radiogroup");
            syms.setAttribute("aria-label", i18n.symbol || "Symbol");
            var chosen = d.symbol || "pin";
            Object.keys(SYMBOLS).forEach(function (k) {
                var b = el("button", "gpx-mk-symbtn", SYMBOLS[k]);
                b.type = "button";
                b.setAttribute("role", "radio");
                b.setAttribute("aria-checked", String(k === chosen));
                b.setAttribute("aria-label", k);
                b.addEventListener("click", function () {
                    chosen = k;
                    Array.prototype.forEach.call(syms.children, function (c) {
                        c.setAttribute("aria-checked", String(c === b));
                    });
                });
                syms.appendChild(b);
            });

            var pubLab = el("label", "gpx-mk-publab");
            var pub = el("input");
            pub.type = "checkbox";
            pub.checked = !!d.isPublic;
            pubLab.appendChild(pub);
            pubLab.appendChild(el("span", "", i18n.showPublic || "Ukázat i návštěvníkům"));

            var row = el("div", "gpx-mk-btns");
            var ok = el("button", "gpx-tl-btn gpx-mk-save", "💾 " + (i18n.save || "Uložit"));
            ok.type = "submit";
            var cancel = el("button", "gpx-tl-btn", i18n.cancel || "Zrušit");
            cancel.type = "button";
            row.appendChild(ok);
            row.appendChild(cancel);

            [t, n, syms, pubLab, row].forEach(function (c) { form.appendChild(c); });

            var popup = L.popup({ maxWidth: 320, minWidth: 260, autoClose: true, closeOnClick: false })
                .setLatLng(marker ? marker.getLatLng() : [d.lat, d.lon])
                .setContent(form)
                .openOn(map);
            setTimeout(function () { t.focus(); }, 50);

            cancel.addEventListener("click", function () { map.closePopup(popup); });
            form.addEventListener("submit", function (ev) {
                ev.preventDefault();
                var title = t.value.trim();
                if (!title) { t.focus(); return; }
                ok.disabled = true;
                var ll = marker ? marker.getLatLng() : L.latLng(d.lat, d.lon);
                save({ id: d.id || 0, lat: ll.lat, lon: ll.lng, title: title,
                       note: n.value.trim(), symbol: chosen, isPublic: pub.checked })
                    .then(function (m) {
                        ok.disabled = false;
                        if (!m) return;
                        map.closePopup(popup);
                        m.openPopup();
                    });
            });
            // Klávesy ve formuláři nesmí ovládat mapu (šipky, +/-)
            L.DomEvent.disableClickPropagation(form);
            form.addEventListener("keydown", function (ev) { ev.stopPropagation(); });
        }

        function startAdd(latlng) {
            if (!opts.isAdmin) return;
            if (opts.ensureOn) opts.ensureOn();
            load();
            openForm({ id: 0, lat: latlng.lat, lon: latlng.lng, title: "", note: "", symbol: "pin", isPublic: false }, null);
        }

        return { layer: group, startAdd: startAdd };
    }

    window.GpxMapMarkers = { create: create };
})();
