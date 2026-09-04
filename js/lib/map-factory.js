/**
 * GPX Manager — shared Leaflet map factory
 * Exposes: window.GpxMapFactory.{ createBaseLayers, createMapillaryOverlay, createWikimediaLayer, createLocateControl }
 *
 * Dependencies (must be loaded before this file):
 *   - Leaflet 1.9.x
 *   - leaflet.vectorgrid (optional — Mapillary only)
 */
window.GpxMapFactory = (function () {
    "use strict";

    /**
     * Build the standard set of base tile layers and the Waymarked overlay.
     *
     * @param {{ tf?: string, mapycom?: string }} apiKeys
     * @param {L.Map} map  — the Leaflet map instance; baseOSM is added to it automatically
     * @returns {{ baseLayers: object, overlayLayers: object, baseOSM: L.TileLayer }}
     */
    function createBaseLayers(apiKeys, map) {
        var tf       = (apiKeys && apiKeys.tf)       || "";
        var mapycom  = (apiKeys && apiKeys.mapycom)  || "";

        var baseOSM = L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            maxZoom: 19,
            attribution: "© OpenStreetMap"
        }).addTo(map);

        var baseTopo = L.tileLayer("https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png", {
            maxZoom: 17,
            attribution: "© OpenTopoMap"
        });

        var baseSat = L.tileLayer(
            "https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}",
            { maxZoom: 19, attribution: "© Esri" }
        );

        /* {r} nahradí Leaflet za "@2x" na displeji s vysokou hustotou bodů —
           dlaždice pak přijde v 512 px na stejné území a mapa je ostrá i na
           telefonu. Počet požadavků se nemění, jen jsou dlaždice větší.

           Záměrně BEZ detectRetina: to by místo @2x tahalo dlaždice o zoom výš
           a kreslilo je do poloviční velikosti — obojí dohromady by znamenalo
           čtyřnásobek požadavků. Retinu mají jen sady basic a outdoor
           (u winter a aerial vrací API 404, ověřeno). */
        var baseMapyCOMBasic = L.tileLayer(
            "https://api.mapy.com/v1/maptiles/basic/256{r}/{z}/{x}/{y}?apikey=" + mapycom,
            { maxZoom: 19, attribution: "© <a href=\"https://mapy.com\" target=\"_blank\">Mapy.com</a>, © OpenStreetMap" }
        );

        var baseMapyCOMTurist = L.tileLayer(
            "https://api.mapy.com/v1/maptiles/outdoor/256{r}/{z}/{x}/{y}?apikey=" + mapycom,
            { maxZoom: 19, attribution: "© <a href=\"https://mapy.com\" target=\"_blank\">Mapy.com</a>, © OpenStreetMap" }
        );

        var baseMapyCOMWinter = L.tileLayer(
            "https://api.mapy.com/v1/maptiles/winter/256/{z}/{x}/{y}?apikey=" + mapycom,
            { maxZoom: 19, attribution: "© <a href=\"https://mapy.com\" target=\"_blank\">Mapy.com</a>, © OpenStreetMap" }
        );

        var baseMapyCOMAerial = L.tileLayer(
            "https://api.mapy.com/v1/maptiles/aerial/256/{z}/{x}/{y}?apikey=" + mapycom,
            { maxZoom: 20, attribution: "© <a href=\"https://mapy.com\" target=\"_blank\">Mapy.com</a>" }
        );

        var baseThunderforest = L.tileLayer(
            "https://{s}.tile.thunderforest.com/outdoors/{z}/{x}/{y}.png?apikey=" + tf,
            { maxZoom: 22, attribution: "© Thunderforest, © OpenStreetMap" }
        );

        // CyclOSM — velmi detailní OSM render: VŠECHNY cesty vč. neznačených
        // pěšin a lesních/polních cest (čárkovaně, s povrchem). Zdarma, bez klíče.
        var baseCyclOSM = L.tileLayer(
            "https://{s}.tile-cyclosm.openstreetmap.fr/cyclosm/{z}/{x}/{y}.png",
            { maxZoom: 20, maxNativeZoom: 19, attribution: "© CyclOSM, © OpenStreetMap" }
        );

        // ZTM ČÚZK — Základní topografická mapa ČR (státní mapové dílo).
        // Extrémně detailní vč. polních a lesních cest; pokrývá JEN Česko.
        var baseZTM = L.tileLayer(
            "https://ags.cuzk.cz/arcgis1/rest/services/ZTM_WM/MapServer/tile/{z}/{y}/{x}",
            { maxZoom: 20, maxNativeZoom: 19, attribution: "© ČÚZK" }
        );

        var overlayWaymarked = L.tileLayer(
            "https://tile.waymarkedtrails.org/hiking/{z}/{x}/{y}.png",
            { maxZoom: 19, opacity: 0.7, attribution: "© Waymarked Trails, © OpenStreetMap" }
        );

        var overlayWaymarkedCycling = L.tileLayer(
            "https://tile.waymarkedtrails.org/cycling/{z}/{x}/{y}.png",
            { maxZoom: 19, opacity: 0.7, attribution: "© Waymarked Trails, © OpenStreetMap" }
        );

        var overlayWaymarkedMtb = L.tileLayer(
            "https://tile.waymarkedtrails.org/mtb/{z}/{x}/{y}.png",
            { maxZoom: 19, opacity: 0.7, attribution: "© Waymarked Trails, © OpenStreetMap" }
        );

        // Stínování terénu — poloprůhledný overlay nad libovolným podkladem
        var overlayHillshade = L.tileLayer(
            "https://server.arcgisonline.com/ArcGIS/rest/services/Elevation/World_Hillshade/MapServer/tile/{z}/{y}/{x}",
            { maxZoom: 19, maxNativeZoom: 15, opacity: 0.35, attribution: "© Esri" }
        );

        var baseLayers = {
            "🗺️ OSM":                 baseOSM,
            "🏞️ Topo":                baseTopo,
            "🌍 Satelit (Esri)":            baseSat,
            "🗺️ Mapy.com základní":   baseMapyCOMBasic,
            "🧭 Mapy.com turistická":  baseMapyCOMTurist,
            "❄️ Mapy.com zimní":       baseMapyCOMWinter,
            "✈️ Mapy.com letecká":     baseMapyCOMAerial,
            "🤾 Thunderforest":              baseThunderforest,
            "🌲 CyclOSM (všechny cesty)":    baseCyclOSM,
            "🇨🇿 ZTM ČÚZK (jen Česko)":       baseZTM,
        };

        var overlayLayers = {
            "🤾 Turistické značení (Waymarked)": overlayWaymarked,
            "🚴 Cyklotrasy (Waymarked)": overlayWaymarkedCycling,
            "🚵 MTB trasy (Waymarked)": overlayWaymarkedMtb,
            "⛰️ Stínování terénu (Esri)": overlayHillshade,
            "📌 Turistické body (OSM)": createPoiLayer(map),
        };

        // Názvy a hranice na průhledném podkladu (Mapy.com). Hlavní smysl je nad
        // leteckou a satelitní mapou — ty jsou jinak bez jediného popisku.
        // Bez klíče se vrstva vůbec nenabízí, ať v seznamu nesvítí mrtvá položka.
        if (mapycom) {
            overlayLayers["🏷️ Popisky a hranice (Mapy.com)"] = L.tileLayer(
                "https://api.mapy.com/v1/maptiles/names-overlay/256/{z}/{x}/{y}?apikey=" + mapycom,
                { maxZoom: 20, attribution: "© <a href=\"https://mapy.com\" target=\"_blank\">Mapy.com</a>" }
            );
        }

        /* Administrátor může vrstvy vypnout a přeskládat (Administrace → Vrstvy map).
           Konfigurace přichází z includes/layout_header.php jako window.gpxMapLayers
           a je vedená názvy vrstev — tedy tím, co je vidět v ovladači a co si
           prohlížeč pamatuje jako naposledy zvolený podklad. */
        baseLayers    = applyLayerConfig(baseLayers, "base");
        overlayLayers = applyLayerConfig(overlayLayers, "overlay");

        // Podklad přidaný na mapu mohl být právě vypnutý — vzít první zapnutý,
        // ať mapa nezůstane bez podkladu.
        if (!baseLayers["🗺️ OSM"]) {
            map.removeLayer(baseOSM);
            var prvni = Object.keys(baseLayers)[0];
            if (prvni) { baseOSM = baseLayers[prvni]; baseOSM.addTo(map); }
        }

        return { baseLayers: baseLayers, overlayLayers: overlayLayers, baseOSM: baseOSM };
    }

    /**
     * Profiltruje a přeskládá vrstvy podle nastavení z administrace.
     * Bez konfigurace (nebo když ji stránka nedostane) vrací vstup beze změny.
     *
     * @param {object} layers   { "název vrstvy": vrstva }
     * @param {string} section  "base" | "overlay"
     */
    function applyLayerConfig(layers, section) {
        var cfg = window.gpxMapLayers;
        if (!cfg) return layers;

        var off   = cfg.off || [];
        var order = (cfg.order && cfg.order[section]) || [];
        var out   = {};

        // Nejdřív v pořadí ze správy, pak případné vrstvy, o kterých registr neví
        order.forEach(function (name) {
            if (layers[name] && off.indexOf(name) === -1) out[name] = layers[name];
        });
        Object.keys(layers).forEach(function (name) {
            if (!out[name] && off.indexOf(name) === -1) out[name] = layers[name];
        });
        return out;
    }

    /**
     * Turistické body zájmu z OSM (vrcholy, rozhledny, přístřešky, prameny,
     * hrady/zříceniny) — načítá se přes serverovou proxy api/poi/bbox.php
     * (Overpass s diskovou cache). Zobrazuje se od zoomu 11.
     *
     * @param {L.Map} map
     * @returns {L.LayerGroup}
     */
    function createPoiLayer(map) {
        var POI_MIN_ZOOM = 11;
        var poiIcons = {
            peak:      "⛰️",
            viewpoint: "🔭",
            shelter:   "🛖",
            spring:    "💧",
            castle:    "🏰",
            ruins:     "🏚️",
            tower:     "🗼"
        };
        var poiLabels = {
            peak:      "vrchol",
            viewpoint: "vyhlídka",
            shelter:   "přístřešek",
            spring:    "pramen",
            castle:    "hrad/zámek",
            ruins:     "zřícenina",
            tower:     "rozhledna"
        };

        var group   = L.layerGroup();
        var loading = false;

        async function load() {
            if (loading || !map.hasLayer(group)) return;
            if (map.getZoom() < POI_MIN_ZOOM) { group.clearLayers(); return; }

            loading = true;
            var b = map.getBounds();
            var url = "api/poi/bbox.php?s=" + b.getSouth().toFixed(4)
                + "&w=" + b.getWest().toFixed(4)
                + "&n=" + b.getNorth().toFixed(4)
                + "&e=" + b.getEast().toFixed(4);
            try {
                var res  = await fetch(url);
                var data = await res.json();
                if (!data.ok || !Array.isArray(data.pois)) return;

                group.clearLayers();
                data.pois.forEach(function (p) {
                    var icon = L.divIcon({
                        className: "",
                        html: "<div class=\"gpx-poi\">" + (poiIcons[p.type] || "📌") + "</div>",
                        iconSize: [22, 22], iconAnchor: [11, 11]
                    });
                    var label = (p.name ? p.name : (poiLabels[p.type] || p.type))
                        + (p.ele !== null && p.ele !== undefined ? " (" + p.ele + " m)" : "");
                    group.addLayer(
                        L.marker([p.lat, p.lon], { icon: icon, keyboard: false })
                            .bindTooltip(label, { direction: "top", offset: [0, -10] })
                    );
                });
            } catch (err) {
                if (window.GPX_DEBUG) console.error("POI chyba:", err);
            } finally {
                loading = false;
            }
        }

        map.on("moveend zoomend", function () {
            if (map.hasLayer(group)) load();
        });
        map.on("overlayadd", function (ev) {
            if (ev.layer === group) load();
        });

        return group;
    }

    /**
     * Add a "my location" control (📍) to the map.
     * Uses browser geolocation — works only in a secure context (HTTPS);
     * on plain HTTP the browser rejects the request and an alert is shown.
     *
     * @param {L.Map} map
     * @returns {L.Control|null}
     */
    function createLocateControl(map) {
        if (!("geolocation" in navigator)) return null;

        var marker = null;
        var circle = null;

        var LocateControl = L.Control.extend({
            options: { position: "topleft" },
            onAdd: function () {
                var container = L.DomUtil.create("div", "leaflet-bar");
                var btn = L.DomUtil.create("a", "", container);
                btn.href = "#";
                btn.title = "Moje poloha";
                btn.setAttribute("role", "button");
                btn.setAttribute("aria-label", "Moje poloha");
                btn.innerHTML = "📍";
                btn.style.cssText = "font-size:15px; text-align:center;";

                L.DomEvent.on(btn, "click", function (e) {
                    L.DomEvent.stop(e);
                    btn.innerHTML = "⏳";
                    navigator.geolocation.getCurrentPosition(function (pos) {
                        btn.innerHTML = "📍";
                        var ll  = [pos.coords.latitude, pos.coords.longitude];
                        var acc = pos.coords.accuracy || 0;
                        if (!marker) {
                            circle = L.circle(ll, {
                                radius: acc, color: "#1565c0", weight: 1, fillColor: "#1565c0", fillOpacity: 0.08
                            }).addTo(map);
                            marker = L.circleMarker(ll, {
                                radius: 7, color: "#fff", weight: 2, fillColor: "#1565c0", fillOpacity: 1
                            }).addTo(map);
                        } else {
                            marker.setLatLng(ll);
                            circle.setLatLng(ll).setRadius(acc);
                        }
                        marker.bindTooltip("Moje poloha (±" + Math.round(acc) + " m)");
                        map.setView(ll, Math.max(map.getZoom(), 15));
                    }, function (err) {
                        btn.innerHTML = "📍";
                        alert("Polohu se nepodařilo zjistit"
                            + (err && err.message ? ": " + err.message : ".")
                            + " (Vyžaduje HTTPS a povolení polohy v prohlížeči.)");
                    }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 });
                });

                return container;
            }
        });

        var control = new LocateControl();
        control.addTo(map);
        return control;
    }

    /**
     * Build the Mapillary VectorGrid overlay.
     * Returns null when token is missing or leaflet.vectorgrid is not loaded.
     *
     * @param {string} token  — Mapillary access token
     * @returns {L.VectorGrid|null}
     */
    function createMapillaryOverlay(token) {
        if (!token || typeof L.vectorGrid === "undefined") return null;

        var layer = L.vectorGrid.protobuf(
            "https://tiles.mapillary.com/maps/vtp/mly1_public/2/{z}/{x}/{y}?access_token=" + token,
            {
                vectorTileLayerStyles: {
                    sequence: { weight: 2, color: "#05CB63", opacity: 0.8, fill: false },
                    image:    { radius: 3, fillColor: "#05CB63", fillOpacity: 0.8, color: "#fff", weight: 1, fill: true },
                    overview: { weight: 2, color: "#05CB63", opacity: 0.6, fill: false }
                },
                interactive: true,
                maxNativeZoom: 14
            }
        );

        layer.on("click", function (e) {
            var props = e.layer.properties;
            if (props && props.image_id) {
                var url  = "https://www.mapillary.com/app/?focus=photo&pKey=" + props.image_id;
                var w = 800, h = 600;
                var left = Math.round((screen.width  - w) / 2);
                var top  = Math.round((screen.height - h) / 2);
                window.open(url, "mapillary_photo",
                    "width=" + w + ",height=" + h + ",left=" + left + ",top=" + top + ",resizable=yes,scrollbars=yes");
            }
        });

        return layer;
    }

    /**
     * Create a Wikimedia Commons photo layer and bind it to map move/zoom events.
     * Returns a L.LayerGroup that can be added to the layer control.
     *
     * @param {L.Map} map
     * @returns {L.LayerGroup}
     */
    function createWikimediaLayer(map) {
        var wikimediaMarkers = L.layerGroup();
        var loading = false;

        async function load() {
            if (loading || !map.hasLayer(wikimediaMarkers)) return;
            if (map.getZoom() < 12) { wikimediaMarkers.clearLayers(); return; }

            loading = true;
            var bounds = map.getBounds();
            var center = bounds.getCenter();
            var url = "https://commons.wikimedia.org/w/api.php?"
                + "action=query&list=geosearch"
                + "&gscoord=" + center.lat + "|" + center.lng
                + "&gsradius=10000&gslimit=50&gsnamespace=6"
                + "&prop=imageinfo&iiprop=url|extmetadata"
                + "&format=json&origin=*";

            try {
                var res   = await fetch(url);
                var data  = await res.json();
                var items = (data && data.query && data.query.geosearch || []).filter(function (i) { return i.lat && i.lon; });

                wikimediaMarkers.clearLayers();

                var results = await Promise.all(items.map(function (item) {
                    var infoUrl = "https://commons.wikimedia.org/w/api.php?"
                        + "action=query&titles=" + encodeURIComponent(item.title)
                        + "&prop=imageinfo&iiprop=url|thumburl|extmetadata"
                        + "&iiurlwidth=400&format=json&origin=*";
                    return fetch(infoUrl).then(function (r) { return r.json(); }).then(function (d) { return { item: item, data: d }; }).catch(function () { return null; });
                }));

                for (var ri = 0; ri < results.length; ri++) {
                    var result = results[ri];
                    if (!result) continue;
                    var item  = result.item;
                    var d     = result.data;
                    var pages = (d && d.query && d.query.pages) || {};
                    var page  = Object.values(pages)[0];
                    var info  = page && page.imageinfo && page.imageinfo[0];
                    if (!info) continue;

                    var thumbUrl = (info.thumburl || info.url || "").replace(/["<>]/g, "");
                    var title    = (page.title || "").replace("File:", "").replace(/[<>]/g, "");
                    var pageUrl  = "https://commons.wikimedia.org/wiki/" + encodeURIComponent(page.title);

                    var icon = L.divIcon({
                        className: "",
                        html: "<div class=\"wiki-marker\">📷</div>",
                        iconSize:   [28, 28],
                        iconAnchor: [14, 14],
                    });

                    var popupEl = document.createElement("div");
                    popupEl.style.cssText = "max-width:320px; font-size:13px;";

                    var img = document.createElement("img");
                    img.src = thumbUrl;
                    img.style.cssText = "width:100%; border-radius:4px; margin-bottom:6px;";
                    img.onerror = function () { this.style.display = "none"; };
                    popupEl.appendChild(img);

                    var titleDiv = document.createElement("div");
                    titleDiv.style.cssText = "font-weight:600; margin-bottom:4px;";
                    titleDiv.textContent = title;
                    popupEl.appendChild(titleDiv);

                    var link = document.createElement("a");
                    link.href = pageUrl;
                    link.target = "_blank";
                    link.style.cssText = "color:#0078d7; font-size:12px;";
                    link.textContent = "Zobrazit na Wikimedia Commons →";
                    popupEl.appendChild(link);

                    wikimediaMarkers.addLayer(
                        L.marker([item.lat, item.lon], { icon: icon }).bindPopup(popupEl, { maxWidth: 340 })
                    );
                }
            } catch (err) {
                if (window.GPX_DEBUG) console.error("Wikimedia chyba:", err);
            } finally {
                loading = false;
            }
        }

        map.on("moveend zoomend", function () {
            if (map.hasLayer(wikimediaMarkers)) load();
        });

        // Expose loader so callers can trigger it on overlayadd
        wikimediaMarkers.loadPhotos = load;

        return wikimediaMarkers;
    }

    /**
     * Aktuální srážky z radaru ČHMÚ (opendata, bez klíče a bez registrace).
     *
     * Snímky jsou PNG v EPSG:3857 s pevným ohraničením, takže stačí
     * L.imageOverlay — Leaflet obrázek roztáhne lineárně v mercatorových
     * pixelech obrazovky, což je přesně to, co projekce vyžaduje.
     *
     * Pozor, tohle je jiný radar než ten v přehrávači výšlapu: ten ukazuje
     * ARCHIV pro dobu trvání trasy a stahuje se na server. Tenhle ukazuje
     * to, co prší TEĎ, a tahá se přímo z ČHMÚ do prohlížeče.
     *
     * @param {L.Map} map
     * @param {object} i18n  texty {frame, opacity}
     * @returns {L.ImageOverlay}
     */
    function createRadarNowOverlay(map, i18n) {
        i18n = i18n || {};

        // Ohraničení celého PNG. Odpovídá georeferenci z dokumentace ČHMÚ
        // (JZ roh 48,0475 N / 11,267 E, 680×460 px po 1555,7 m v Mercatoru) —
        // stejné číslo, jaké si dopočítává přehrávač v js/detail-replay.js.
        var BOUNDS   = [[48.0475, 11.267], [52.1670, 20.7701]];
        var BASE     = "https://opendata.chmi.cz/meteorology/weather/radar/composite/maxz/png_masked/";
        var STEP_MS  = 5 * 60 * 1000;   // krok produktu maxz
        var MAX_BACK = 5;               // dokumentované zpoždění je 3–15 min
        var BLANK    = "data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7";

        // Rychlosti animace: doba jednoho snímku v ms
        var SPEEDS = { slow: 900, mid: 550, fast: 300 };
        // Poslední snímek se drží déle, ať je poznat, kde smyčka končí
        var HOLD_LAST = 2.2;

        var KEY_OPACITY = "gpx_radar_now_opacity";
        var KEY_SPEED   = "gpx_radar_now_speed";
        var KEY_COUNT   = "gpx_radar_now_frames";
        var KEY_PLAY    = "gpx_radar_now_play";

        function readLS(key, fallback) {
            try { var v = localStorage.getItem(key); return v === null ? fallback : v; }
            catch (e) { return fallback; }
        }
        function writeLS(key, value) {
            try { localStorage.setItem(key, String(value)); } catch (e) { /* privátní režim */ }
        }

        var opacity = Math.min(100, Math.max(20, parseInt(readLS(KEY_OPACITY, "80"), 10) || 80));
        var speed   = SPEEDS[readLS(KEY_SPEED, "mid")] ? readLS(KEY_SPEED, "mid") : "mid";
        var count   = Math.min(24, Math.max(1, parseInt(readLS(KEY_COUNT, "12"), 10) || 12));
        var playing = readLS(KEY_PLAY, "0") === "1";

        var layer = L.imageOverlay(BLANK, BOUNDS, {
            opacity: opacity / 100,
            interactive: false,
            attribution: 'radar &copy; <a href="https://opendata.chmi.cz" target="_blank" rel="noopener">ČHMÚ</a>'
        });

        var frames = [];        // [{ms, url}] vzestupně, nejnovější poslední
        var idx = 0;
        var playTimer = null;   // řetězený setTimeout (poslední snímek drží déle)
        var loadTimer = null;   // obnova seznamu každých 5 minut
        var loading = false;

        /** YYYYMMDD.hhmm v UTC, zarovnané na pětiminutový krok. */
        function stampOf(ms) {
            var d = new Date(Math.floor(ms / STEP_MS) * STEP_MS);
            function p(n) { return (n < 10 ? "0" : "") + n; }
            return d.getUTCFullYear() + p(d.getUTCMonth() + 1) + p(d.getUTCDate())
                 + "." + p(d.getUTCHours()) + p(d.getUTCMinutes());
        }
        function urlFor(ms) { return BASE + "pacz2gmaps3.z_max3d." + stampOf(ms) + ".0.png"; }

        /**
         * Existenci snímku ověřuje new Image(), ne fetch — obrázky CORS
         * nepotřebují, kdežto fetch ano a ČHMÚ hlavičky neposílá.
         * Vedlejší efekt je vítaný: co se ověří, je rovnou v cache prohlížeče,
         * takže animace neškube.
         */
        function probe(url) {
            return new Promise(function (resolve) {
                var im = new Image();
                im.onload  = function () { resolve(true); };
                im.onerror = function () { resolve(false); };
                im.src = url;
            });
        }

        /** Načte posledních N existujících snímků, seřazených vzestupně. */
        async function loadFrames() {
            if (loading) return;
            loading = true;
            var base = Math.floor(Date.now() / STEP_MS) * STEP_MS;
            var cand = [];
            // Pár kroků navíc kvůli zpoždění publikace a občas chybějícímu snímku
            for (var i = 0; i < count + MAX_BACK; i++) cand.push(base - i * STEP_MS);

            var oks = await Promise.all(cand.map(function (ms) { return probe(urlFor(ms)); }));
            var list = [];
            for (var j = 0; j < cand.length && list.length < count; j++) {
                if (oks[j]) list.push({ ms: cand[j], url: urlFor(cand[j]) });
            }
            loading = false;

            if (!list.length) {
                // Radar je doplněk — výpadek ČHMÚ nesmí nic rušit, jen se zapíše do konzole
                if (window.GPX_DEBUG) console.warn("radar ČHMÚ: žádný snímek k dispozici");
                return;
            }
            frames = list.reverse();
            idx = frames.length - 1;          // po načtení stojíme na nejnovějším
            show(idx);
            if (playing) startPlay();
        }

        function show(i) {
            if (!frames[i]) return;
            idx = i;
            layer.setUrl(frames[i].url);
            control.setTime(new Date(frames[i].ms), i, frames.length);
        }

        function step() {
            if (!frames.length) return;
            var next = (idx + 1) % frames.length;
            show(next);
            var wait = SPEEDS[speed] * (next === frames.length - 1 ? HOLD_LAST : 1);
            playTimer = setTimeout(step, wait);
        }

        function startPlay() {
            stopPlay(false);
            if (frames.length < 2) return;
            playing = true;
            writeLS(KEY_PLAY, "1");
            control.setPlaying(true);
            playTimer = setTimeout(step, SPEEDS[speed]);
        }

        function stopPlay(remember) {
            if (playTimer) { clearTimeout(playTimer); playTimer = null; }
            if (remember !== false) {
                playing = false;
                writeLS(KEY_PLAY, "0");
            }
            control.setPlaying(playing);
        }

        /* Ovladač v rohu mapy: čas snímku, přehrávání, rychlost, délka smyčky
           a průhlednost. Do seznamu vrstev se tohle vložit nedá, je to jen
           seznam zaškrtávátek. */
        var control = L.control({ position: "bottomleft" });

        control.onAdd = function () {
            var div = L.DomUtil.create("div", "gpx-radar-ctl");
            div.innerHTML =
                '<div class="gpx-radar-time">🌧️ <span>–</span></div>' +
                '<div class="gpx-radar-row">' +
                  '<button type="button" class="gpx-radar-play" title="' + (i18n.play || "přehrát") + '">▶</button>' +
                  '<select class="gpx-radar-speed" title="' + (i18n.speed || "rychlost") + '">' +
                    '<option value="slow">0,5×</option>' +
                    '<option value="mid">1×</option>' +
                    '<option value="fast">2×</option>' +
                  '</select>' +
                  '<select class="gpx-radar-count" title="' + (i18n.frames || "délka smyčky") + '">' +
                    '<option value="6">30 min</option>' +
                    '<option value="12">1 h</option>' +
                    '<option value="18">1,5 h</option>' +
                    '<option value="24">2 h</option>' +
                  '</select>' +
                '</div>' +
                '<label class="gpx-radar-op">' + (i18n.opacity || "průhlednost") +
                ' <input type="range" min="20" max="100" step="5" value="' + opacity + '"></label>';
            L.DomEvent.disableClickPropagation(div);

            this._timeEl  = div.querySelector(".gpx-radar-time span");
            this._playEl  = div.querySelector(".gpx-radar-play");
            var speedEl   = div.querySelector(".gpx-radar-speed");
            var countEl   = div.querySelector(".gpx-radar-count");
            var slider    = div.querySelector("input[type=range]");

            speedEl.value = speed;
            countEl.value = String(count);

            L.DomEvent.on(this._playEl, "click", function () {
                if (playing) { stopPlay(); show(frames.length - 1); }
                else startPlay();
            });

            L.DomEvent.on(speedEl, "change", function () {
                speed = SPEEDS[speedEl.value] ? speedEl.value : "mid";
                writeLS(KEY_SPEED, speed);
                if (playing) startPlay();       // převzít nové tempo hned
            });

            L.DomEvent.on(countEl, "change", function () {
                count = Math.min(24, Math.max(1, parseInt(countEl.value, 10) || 12));
                writeLS(KEY_COUNT, count);
                stopPlay(false);                // přehrávání si pamatujeme, jen ho na chvíli zastavíme
                loadFrames();
            });

            L.DomEvent.on(slider, "input", function () {
                var v = parseInt(slider.value, 10);
                layer.setOpacity(v / 100);
                writeLS(KEY_OPACITY, v);
            });

            return div;
        };

        /** Čas snímku v místním čase — bez něj uživatel nepozná stáří dat. */
        control.setTime = function (d, i, total) {
            if (!this._timeEl) return;
            var hh = d.getHours(), mm = d.getMinutes();
            var text = (i18n.frame || "snímek z") + " " + hh + ":" + (mm < 10 ? "0" : "") + mm;
            if (total > 1) text += "  (" + (i + 1) + "/" + total + ")";
            this._timeEl.textContent = text;
        };

        control.setPlaying = function (on) {
            if (!this._playEl) return;
            this._playEl.textContent = on ? "⏸" : "▶";
            this._playEl.title = on ? (i18n.pause || "pauza") : (i18n.play || "přehrát");
        };

        map.on("overlayadd", function (e) {
            if (e.layer !== layer) return;
            control.addTo(map);
            control.setPlaying(playing);
            loadFrames();
            if (loadTimer) clearInterval(loadTimer);
            loadTimer = setInterval(loadFrames, STEP_MS);
        });

        map.on("overlayremove", function (e) {
            if (e.layer !== layer) return;
            // Vypnutá vrstva nesmí nic dělat na pozadí — ani časovač, ani požadavky
            stopPlay(false);
            if (loadTimer) { clearInterval(loadTimer); loadTimer = null; }
            map.removeControl(control);
            frames = [];
        });

        return layer;
    }

    return {
        createBaseLayers:      createBaseLayers,
        createMapillaryOverlay: createMapillaryOverlay,
        createWikimediaLayer:  createWikimediaLayer,
        createLocateControl:   createLocateControl,
        createRadarNowOverlay: createRadarNowOverlay,
        applyLayerConfig:      applyLayerConfig,
    };
})();
