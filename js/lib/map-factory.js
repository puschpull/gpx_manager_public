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

        var baseMapyCOMBasic = L.tileLayer(
            "https://api.mapy.com/v1/maptiles/basic/256/{z}/{x}/{y}?apikey=" + mapycom,
            { maxZoom: 19, attribution: "© <a href=\"https://mapy.com\" target=\"_blank\">Mapy.com</a>, © OpenStreetMap" }
        );

        var baseMapyCOMTurist = L.tileLayer(
            "https://api.mapy.com/v1/maptiles/outdoor/256/{z}/{x}/{y}?apikey=" + mapycom,
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

        return { baseLayers: baseLayers, overlayLayers: overlayLayers, baseOSM: baseOSM };
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

    return {
        createBaseLayers:      createBaseLayers,
        createMapillaryOverlay: createMapillaryOverlay,
        createWikimediaLayer:  createWikimediaLayer,
        createLocateControl:   createLocateControl,
    };
})();
