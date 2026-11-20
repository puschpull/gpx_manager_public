/**
 * GPX Manager — stránka Mapa: hledání místa podle názvu nebo souřadnic.
 *
 * Políčko vpravo nahoře nad panelem vrstev. Názvy hledá Mapy.com
 * (api.mapy.com/v1/suggest, stejný klíč jako dlaždice; v CSP connect-src
 * povoleno). Souřadnice se rozpoznají bez dotazu na server:
 *   50.0875, 14.4214   ·   50,0875 14,4214   ·   50°5′15″N 14°25′17″E
 *   S-JTSK: -743180.41, -1042747.36  (i bez minusů)
 * Bez klíče k Mapy.com funguje jen hledání souřadnic.
 */
(function () {
    "use strict";

    var SUGGEST = "https://api.mapy.com/v1/suggest";
    var LANGS   = ["cs", "en", "de", "sk", "pl", "es", "fr", "it"];

    /* Přiblížení podle druhu místa, když služba nevrátí rozsah */
    function zoomFor(type) {
        if (/address|street/.test(type)) return 17;
        if (type === "poi") return 16;
        if (/municipality_part|ward/.test(type)) return 14;
        if (/municipality/.test(type)) return 13;
        if (/region/.test(type)) return 9;
        if (/country/.test(type)) return 7;
        return 14;
    }

    /* ===== Rozpoznání souřadnic ===== */
    function dmsPart(str) {
        // 50°5′15.2″N  /  50°5.25'N  /  50° N
        var m = /(\d+(?:[.,]\d+)?)\s*°\s*(?:(\d+(?:[.,]\d+)?)\s*['′’]\s*)?(?:(\d+(?:[.,]\d+)?)\s*(?:["″”]|'')\s*)?([NSEWnsew])?/.exec(str);
        if (!m) return null;
        var n = function (v) { return v ? parseFloat(v.replace(",", ".")) : 0; };
        var v = n(m[1]) + n(m[2]) / 60 + n(m[3]) / 3600;
        var hemi = (m[4] || "").toUpperCase();
        if (hemi === "S" || hemi === "W") v = -v;
        return { v: v, hemi: hemi, end: m.index + m[0].length };
    }

    function parseCoords(q, fromJtsk) {
        var s = q.trim();
        if (!s) return null;

        if (s.indexOf("°") !== -1) {
            var a = dmsPart(s);
            if (!a) return null;
            var b = dmsPart(s.slice(a.end));
            if (!b) return null;
            var lat = a.v, lon = b.v;
            if (a.hemi === "E" || a.hemi === "W") { lat = b.v; lon = a.v; }
            return valid(lat, lon) ? { lat: lat, lng: lon, kind: "gps" } : null;
        }

        // Dvě čísla: oddělená středníkem, „čárkou a mezerou“ nebo mezerou;
        // desetinná čárka je povolená. Písmena N/E na konci se ignorují.
        var clean = s.replace(/[NSEWnsew]/g, " ").trim();
        var parts = clean.split(/\s*;\s*|,\s+|\s+/).filter(Boolean);
        if (parts.length === 1 && (parts[0].match(/,/g) || []).length === 1) parts = parts[0].split(",");
        if (parts.length !== 2) return null;
        var nums = parts.map(function (p) { return /^-?\d+(?:[.,]\d+)?$/.test(p) ? parseFloat(p.replace(",", ".")) : NaN; });
        if (isNaN(nums[0]) || isNaN(nums[1])) return null;

        var x0 = Math.abs(nums[0]), x1 = Math.abs(nums[1]);
        if (x0 > 100000 && x1 > 100000) {
            if (!fromJtsk) return null;
            // S-JTSK: menší číslo je Y (430–910 tis.), větší X (930–1 230 tis.)
            var ll = fromJtsk(-Math.min(x0, x1), -Math.max(x0, x1));
            return valid(ll.lat, ll.lng) ? { lat: ll.lat, lng: ll.lng, kind: "jtsk" } : null;
        }
        var la = nums[0], lo = nums[1];
        // „14.42, 50.08“ (nejdřív délka) — v Evropě je šířka větší než délka
        if (Math.abs(la) < Math.abs(lo) && Math.abs(lo) <= 90 && Math.abs(la) <= 40) { la = nums[1]; lo = nums[0]; }
        return valid(la, lo) ? { lat: la, lng: lo, kind: "gps" } : null;
    }

    function valid(lat, lon) {
        return isFinite(lat) && isFinite(lon) && Math.abs(lat) <= 90 && Math.abs(lon) <= 180;
    }

    function el(tag, cls, txt) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (txt !== undefined) n.textContent = txt;
        return n;
    }

    /**
     * @param {L.Map} map
     * @param {{ i18n: object, apiKey: string, fromJtsk?: function }} opts
     */
    function create(map, opts) {
        var i18n   = opts.i18n || {};
        var apiKey = opts.apiKey || "";
        var lang   = (document.documentElement.lang || "cs").slice(0, 2);
        if (LANGS.indexOf(lang) === -1) lang = "en";

        var input, list, clearBtn, wrap;
        var results = [], sel = -1, timer = null, ctrl = null, seq = 0;
        var marker = null;

        function setResults(items, msg) {
            results = items;
            sel = items.length ? 0 : -1;
            list.textContent = "";
            if (msg) list.appendChild(el("li", "gpx-se-msg", msg));
            items.forEach(function (it, i) {
                var li = el("li", "gpx-se-item");
                li.id = "gpxSearchOpt" + i;
                li.setAttribute("role", "option");
                li.appendChild(el("span", "gpx-se-name", it.name));
                var sub = [it.label, it.location].filter(Boolean).join(" · ");
                if (sub) li.appendChild(el("span", "gpx-se-sub", sub));
                li.addEventListener("mousedown", function (ev) { ev.preventDefault(); });   // fokus zůstane v políčku
                li.addEventListener("click", function () { choose(i); });
                list.appendChild(li);
            });
            list.hidden = !items.length && !msg;
            input.setAttribute("aria-expanded", String(!list.hidden));
            highlight();
        }

        function highlight() {
            Array.prototype.forEach.call(list.querySelectorAll(".gpx-se-item"), function (li, i) {
                var on = i === sel;
                li.classList.toggle("is-sel", on);
                li.setAttribute("aria-selected", String(on));
            });
            if (sel >= 0) input.setAttribute("aria-activedescendant", "gpxSearchOpt" + sel);
            else input.removeAttribute("aria-activedescendant");
        }

        function close() {
            list.hidden = true;
            input.setAttribute("aria-expanded", "false");
        }

        function showPlace(lat, lng, name, bbox, type) {
            if (marker) map.removeLayer(marker);
            marker = L.circleMarker([lat, lng], {
                radius: 8, color: "#fff", weight: 3, fillColor: "#d1342f", fillOpacity: 1
            }).addTo(map);
            marker.bindTooltip(name, { direction: "top", offset: [0, -8] });
            if (bbox && bbox.length === 4 && (bbox[2] - bbox[0] > 1e-4 || bbox[3] - bbox[1] > 1e-4)) {
                map.fitBounds([[bbox[1], bbox[0]], [bbox[3], bbox[2]]], { maxZoom: 17, padding: [20, 20] });
            } else {
                map.setView([lat, lng], Math.max(map.getZoom(), zoomFor(type || "")));
            }
            marker.openTooltip();
            clearBtn.hidden = false;
        }

        function choose(i) {
            var it = results[i];
            if (!it) return;
            input.value = it.name;
            close();
            showPlace(it.lat, it.lng, it.name, it.bbox, it.type);
        }

        function clearAll() {
            input.value = "";
            setResults([]);
            if (marker) { map.removeLayer(marker); marker = null; }
            clearBtn.hidden = true;
            input.focus();
        }

        function search() {
            var q = input.value.trim();
            var c = parseCoords(q, opts.fromJtsk);
            if (c) {
                var label = (c.kind === "jtsk" ? "S-JTSK → " : "") + c.lat.toFixed(6) + ", " + c.lng.toFixed(6);
                setResults([{ name: label, label: i18n.coords || "Souřadnice", location: "",
                              lat: c.lat, lng: c.lng, bbox: null, type: "address" }]);
                return;
            }
            if (q.length < 2 || !apiKey) { setResults([]); return; }

            if (ctrl) ctrl.abort();
            ctrl = window.AbortController ? new AbortController() : null;
            var my = ++seq;
            var c0 = map.getCenter();
            var url = SUGGEST + "?lang=" + lang + "&limit=8"
                    + "&preferNear=" + c0.lng.toFixed(4) + "," + c0.lat.toFixed(4)
                    + "&query=" + encodeURIComponent(q) + "&apikey=" + encodeURIComponent(apiKey);
            fetch(url, ctrl ? { signal: ctrl.signal } : {})
                .then(function (r) { if (!r.ok) throw new Error("HTTP " + r.status); return r.json(); })
                .then(function (d) {
                    if (my !== seq) return;
                    var items = (d.items || []).filter(function (it) { return it.position; }).map(function (it) {
                        return { name: it.name, label: it.label, location: it.location,
                                 lat: it.position.lat, lng: it.position.lon, bbox: it.bbox, type: it.type || "" };
                    });
                    setResults(items, items.length ? "" : (i18n.none || "Nic nenalezeno"));
                })
                .catch(function (e) {
                    if (e && e.name === "AbortError") return;
                    if (my === seq) setResults([], i18n.error || "Hledání teď nefunguje");
                });
        }

        var Ctl = L.Control.extend({
            options: { position: "topright" },
            onAdd: function () {
                wrap = L.DomUtil.create("div", "gpx-se");
                var form = L.DomUtil.create("form", "gpx-se-form", wrap);
                form.setAttribute("role", "search");
                input = L.DomUtil.create("input", "gpx-se-input", form);
                input.type = "search";
                input.placeholder = "🔍 " + (apiKey ? (i18n.placeholder || "Hledat místo nebo souřadnice")
                                                     : (i18n.placeholderCoords || "Zadat souřadnice"));
                input.setAttribute("aria-label", i18n.placeholder || "Hledat místo nebo souřadnice");
                input.setAttribute("role", "combobox");
                input.setAttribute("aria-autocomplete", "list");
                input.setAttribute("aria-controls", "gpxSearchList");
                input.setAttribute("aria-expanded", "false");
                input.autocomplete = "off";
                input.spellcheck = false;
                clearBtn = L.DomUtil.create("button", "gpx-se-clear", form);
                clearBtn.type = "button";
                clearBtn.textContent = "✕";
                clearBtn.title = i18n.clearSearch || "Zrušit hledání";
                clearBtn.setAttribute("aria-label", clearBtn.title);
                clearBtn.hidden = true;
                list = L.DomUtil.create("ul", "gpx-se-list", wrap);
                list.id = "gpxSearchList";
                list.setAttribute("role", "listbox");
                list.hidden = true;

                input.addEventListener("input", function () {
                    clearTimeout(timer);
                    clearBtn.hidden = !input.value && !marker;
                    timer = setTimeout(search, 250);
                });
                input.addEventListener("keydown", function (ev) {
                    if (ev.key === "ArrowDown" && results.length) {
                        ev.preventDefault();
                        if (list.hidden) { list.hidden = false; input.setAttribute("aria-expanded", "true"); }
                        sel = (sel + 1) % results.length; highlight();
                    } else if (ev.key === "ArrowUp" && results.length) {
                        ev.preventDefault();
                        sel = (sel - 1 + results.length) % results.length; highlight();
                    } else if (ev.key === "Escape") {
                        if (!list.hidden) { ev.preventDefault(); close(); }
                    }
                    ev.stopPropagation();          // šipky a +/- nesmí ovládat mapu
                });
                input.addEventListener("focus", function () { if (results.length) { list.hidden = false; } });
                input.addEventListener("blur", function () { setTimeout(close, 150); });
                form.addEventListener("submit", function (ev) {
                    ev.preventDefault();
                    clearTimeout(timer);
                    if (results.length && sel >= 0 && !list.hidden) { choose(sel); return; }
                    // Enter dřív, než doběhlo hledání: souřadnice rovnou, jinak počkat na výsledek
                    var c = parseCoords(input.value, opts.fromJtsk);
                    if (c) { showPlace(c.lat, c.lng, input.value.trim(), null, "address"); close(); return; }
                    search();
                });
                L.DomEvent.on(clearBtn, "click", clearAll);
                L.DomEvent.disableClickPropagation(wrap);
                L.DomEvent.disableScrollPropagation(wrap);
                return wrap;
            }
        });
        new Ctl().addTo(map);
    }

    window.GpxMapSearch = { create: create, parseCoords: parseCoords };
})();
