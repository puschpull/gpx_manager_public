/**
 * ===========================================================
 *  GPX Manager – Plánovač výšlapu
 *  Klikáním do mapy waypointy → routing po cestách (Mapy.com,
 *  server-side api/planner/route.php) → statistiky, výškový
 *  profil (Open-Meteo elevation) a export GPX pro Garmin.
 * ===========================================================
 */

if (window.GPX_DEBUG) console.log("🥾 planner.js načten");

document.addEventListener("DOMContentLoaded", () => {

    const cfg  = window.gpxPlannerData || {};
    const i18n = cfg.i18n || {};
    const keys = cfg.apiKeys || {};
    const CSRF = cfg.csrfToken || "";
    const PACE = cfg.personalPace || {};   // {foot: km/h|null, bike: km/h|null} z vlastních tras

    // ===== Mapa =====
    const map = L.map("map", { center: [49.8, 15.5], zoom: 8, fullscreenControl: true });

    const { baseLayers, overlayLayers: _overlayBase, baseOSM } = window.GpxMapFactory.createBaseLayers(keys, map);
    const overlayMapillary = window.GpxMapFactory.createMapillaryOverlay(keys.mapillary || "");
    const overlayLayers = Object.assign({}, _overlayBase,
        overlayMapillary ? { "📷 Fotografie (Mapillary)": overlayMapillary } : {});
    L.control.layers(baseLayers, overlayLayers, { collapsed: true }).addTo(map);
    if (window.GpxMapFactory.createLocateControl) window.GpxMapFactory.createLocateControl(map);

    const savedLayer = localStorage.getItem("gpx_map_layer");
    if (savedLayer && baseLayers[savedLayer]) {
        map.removeLayer(baseOSM);
        baseLayers[savedLayer].addTo(map);
    }
    map.on("baselayerchange", e => localStorage.setItem("gpx_map_layer", e.name));

    // ===== Stav =====
    let waypoints  = [];        // [{lat, lng, marker}]
    let routeLine  = null;      // L.polyline
    let routeData  = null;      // poslední odpověď routingu (+ ascent/descent po elevaci)
    let elevChart  = null;      // Chart.js instance
    let computeSeq = 0;         // ochrana proti závodům odpovědí
    let debounceT  = null;
    let currentPlanId = 0;      // 0 = neuložený plán
    let restoring  = false;     // načítání uloženého plánu → nepřepočítávat routing

    const statusEl   = document.getElementById("plan-status");
    const statsEl    = document.getElementById("plan-stats");
    const elevWrap   = document.getElementById("plan-elev-wrap");
    const profileSel = document.getElementById("planProfile");
    const nameInput  = document.getElementById("planName");
    const btnUndo    = document.getElementById("planUndo");
    const btnClear   = document.getElementById("planClear");
    const btnExport  = document.getElementById("planExport");
    const btnSave    = document.getElementById("planSave");
    const btnDelete  = document.getElementById("planDelete");
    const dateInput  = document.getElementById("planDate");
    const planListEl = document.getElementById("planList");
    const weatherEl  = document.getElementById("plan-weather");

    function setStatus(text, cls) {
        if (!statusEl) return;
        statusEl.textContent = text;
        statusEl.className = "nearby-status" + (cls ? " " + cls : "");
    }

    function fmtDur(sec) {
        sec = Math.round(sec);
        const h = Math.floor(sec / 3600), m = Math.round((sec % 3600) / 60);
        return h > 0 ? `${h} h ${m} min` : `${m} min`;
    }

    function fmtKm(m) {
        return (m / 1000).toFixed(2).replace(".", ",") + " km";
    }

    function updateButtons() {
        const hasRoute = !!(routeData && routeData.coords && routeData.coords.length > 1);
        btnUndo.disabled   = waypoints.length === 0;
        btnClear.disabled  = waypoints.length === 0;
        btnExport.disabled = !hasRoute;
        btnSave.disabled   = !hasRoute;
        btnDelete.disabled = !(planListEl.value);
    }

    // ===== Waypoint markery (číslované, tažitelné, klik = smazat) =====
    function wptIcon(n, isLast) {
        const bg = n === 1 ? "#2e7d32" : (isLast ? "#c62828" : "#1565c0");
        return L.divIcon({
            className: "",
            html: `<div class="plan-wpt" style="background:${bg}">${n}</div>`,
            iconSize: [26, 26], iconAnchor: [13, 13]
        });
    }

    function refreshMarkers() {
        waypoints.forEach((w, i) => {
            w.marker.setIcon(wptIcon(i + 1, i === waypoints.length - 1));
        });
        const el = document.getElementById("planWpts");
        if (el) el.textContent = waypoints.length;
    }

    function addWaypoint(latlng, skipCompute) {
        const w = { lat: latlng.lat, lng: latlng.lng, marker: null };
        const m = L.marker([latlng.lat, latlng.lng], {
            icon: wptIcon(waypoints.length + 1, true),
            draggable: true
        }).addTo(map);
        m.on("dragend", () => {
            const p = m.getLatLng();
            w.lat = p.lat; w.lng = p.lng;
            scheduleCompute();
        });
        m.on("click", () => removeWaypoint(w));
        w.marker = m;
        waypoints.push(w);
        refreshMarkers();
        if (!skipCompute) scheduleCompute();
    }

    function removeWaypoint(w) {
        const idx = waypoints.indexOf(w);
        if (idx === -1) return;
        map.removeLayer(w.marker);
        waypoints.splice(idx, 1);
        refreshMarkers();
        scheduleCompute();
    }

    function clearRoute() {
        if (routeLine) { map.removeLayer(routeLine); routeLine = null; }
        routeData = null;
        statsEl.style.display = "none";
        elevWrap.style.display = "none";
        if (elevChart) { elevChart.destroy(); elevChart = null; }
    }

    // ===== Výpočet trasy (debounce — šetří Mapy.com kvótu při rychlém klikání) =====
    function scheduleCompute() {
        if (restoring) return;   // při načítání uloženého plánu se routing nevolá
        updateButtons();
        clearTimeout(debounceT);
        debounceT = setTimeout(compute, 600);
    }

    async function compute() {
        if (waypoints.length < 2) {
            clearRoute();
            updateButtons();
            setStatus(waypoints.length === 1 ? (i18n.onePoint || "Přidej další bod.") : (i18n.clickHint || ""), "");
            return;
        }

        const seq = ++computeSeq;
        setStatus(i18n.computing || "Počítám…", "loading");

        const pts = waypoints.map(w => `${w.lat.toFixed(6)},${w.lng.toFixed(6)}`).join(";");
        const profile = profileSel.value || "foot_hiking";

        try {
            const res  = await fetch(`api/planner/route.php?points=${encodeURIComponent(pts)}&profile=${encodeURIComponent(profile)}`);
            const data = await res.json();
            if (seq !== computeSeq) return;   // mezitím přišel novější požadavek

            if (!data.ok || !Array.isArray(data.coords) || data.coords.length < 2) {
                clearRoute();
                updateButtons();
                setStatus((i18n.error || "Chyba") + ": " + (data.error || "?"), "error");
                return;
            }

            routeData = data;
            if (routeLine) map.removeLayer(routeLine);
            routeLine = L.polyline(data.coords, { color: "#1565c0", weight: 4, opacity: 0.85 }).addTo(map);

            document.getElementById("planDist").textContent = fmtKm(data.length_m);
            document.getElementById("planDur").textContent  = "~" + fmtDur(data.duration_s);
            document.getElementById("planAsc").textContent  = "…";
            document.getElementById("planDesc").textContent = "…";
            statsEl.style.display = "flex";
            setStatus(i18n.done || "Hotovo.", "done");
            updateButtons();

            loadElevation(data.coords, seq);
            loadWeather();
        } catch (err) {
            if (seq !== computeSeq) return;
            if (window.GPX_DEBUG) console.error("planner:", err);
            setStatus((i18n.error || "Chyba") + ".", "error");
        }
    }

    profileSel.addEventListener("change", () => { if (waypoints.length >= 2) scheduleCompute(); });

    // ===== Výškový profil (Open-Meteo elevation API, max 100 bodů/požadavek) =====
    async function loadElevation(coords, seq) {
        // Podvzorkovat na max 100 bodů, spočítat kumulativní vzdálenost
        const maxPts = 100;
        const step = Math.max(1, Math.ceil(coords.length / maxPts));
        const sample = [];
        for (let i = 0; i < coords.length; i += step) sample.push(coords[i]);
        const last = coords[coords.length - 1];
        if (sample[sample.length - 1] !== last) sample.push(last);

        const distKm = [0];
        for (let i = 1; i < sample.length; i++) {
            distKm.push(distKm[i - 1] +
                window.GpxGeo.haversine(sample[i - 1][0], sample[i - 1][1], sample[i][0], sample[i][1]) / 1000);
        }

        try {
            const lat = sample.map(c => c[0].toFixed(5)).join(",");
            const lon = sample.map(c => c[1].toFixed(5)).join(",");
            const res  = await fetch(`https://api.open-meteo.com/v1/elevation?latitude=${lat}&longitude=${lon}`);
            const data = await res.json();
            if (seq !== computeSeq) return;
            const elev = data && data.elevation;
            if (!Array.isArray(elev) || elev.length !== sample.length) return;

            // Stoupání/klesání s hysterezí 2 m (potlačení šumu DEM)
            let asc = 0, desc = 0, ref = elev[0];
            for (let i = 1; i < elev.length; i++) {
                const d = elev[i] - ref;
                if (d >= 2)       { asc  += d;  ref = elev[i]; }
                else if (d <= -2) { desc -= d;  ref = elev[i]; }
            }
            document.getElementById("planAsc").textContent  = Math.round(asc) + " m";
            document.getElementById("planDesc").textContent = Math.round(desc) + " m";
            if (routeData) {
                routeData.ascent  = Math.round(asc);
                routeData.descent = Math.round(desc);
            }
            updatePersonalPace(asc);

            renderElevChart(distKm, elev);
        } catch (err) {
            if (window.GPX_DEBUG) console.error("planner elevation:", err);
            document.getElementById("planAsc").textContent  = "–";
            document.getElementById("planDesc").textContent = "–";
        }
    }

    function renderElevChart(distKm, elev) {
        if (typeof Chart === "undefined") return;
        const canvas = document.getElementById("planElevChart");
        if (!canvas) return;
        if (elevChart) elevChart.destroy();

        const dark = document.documentElement.classList.contains("dark");
        const gridColor = dark ? "rgba(255,255,255,0.12)" : "rgba(0,0,0,0.08)";
        const tickColor = dark ? "#cbd5c0" : "#555";

        elevChart = new Chart(canvas, {
            type: "line",
            data: {
                labels: distKm.map(d => d.toFixed(1)),
                datasets: [{
                    data: elev,
                    borderColor: "#1565c0",
                    backgroundColor: "rgba(21,101,192,0.18)",
                    fill: true,
                    pointRadius: 0,
                    borderWidth: 2,
                    tension: 0.25
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? false : { duration: 300 },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: items => items[0].label.replace(".", ",") + " km",
                            label: item => Math.round(item.parsed.y) + " m n. m."
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: gridColor },
                        ticks: { color: tickColor, maxTicksLimit: 10, callback: function (v, i) { return this.getLabelForValue(i).replace(".", ",") + " km"; } }
                    },
                    y: { grid: { color: gridColor }, ticks: { color: tickColor } }
                }
            }
        });
        elevWrap.style.display = "block";
    }

    // ===== Odhad času z vlastního tempa (Naismith: + stoupání 600 m/h pěšky, 800 m/h kolo) =====
    function updatePersonalPace(ascM) {
        const wrap = document.getElementById("planPaceWrap");
        const out  = document.getElementById("planPace");
        if (!wrap || !out || !routeData) return;
        const profile = profileSel.value || "foot_hiking";
        let pace = null, climbRate = 600;
        if (profile.indexOf("foot") === 0)      { pace = PACE.foot; climbRate = 600; }
        else if (profile.indexOf("bike") === 0) { pace = PACE.bike; climbRate = 800; }
        if (!pace) { wrap.style.display = "none"; return; }
        const sec = (routeData.length_m / 1000) / pace * 3600 + ((ascM || 0) / climbRate) * 3600;
        out.textContent = "~" + fmtDur(sec);
        wrap.style.display = "";
    }

    // ===== Počasí pro plánovaný den (Open-Meteo forecast, střed trasy) =====
    function wmoEmoji(c) {
        if (c === 0) return "☀️";
        if (c <= 2)  return "🌤️";
        if (c === 3) return "☁️";
        if (c <= 49) return "🌫️";
        if (c <= 59) return "🌦️";
        if (c <= 69) return "🌧️";
        if (c <= 79) return "🌨️";
        if (c <= 84) return "🌧️";
        if (c <= 94) return "🌨️";
        return "⛈️";
    }

    async function loadWeather() {
        if (!weatherEl) return;
        const dateVal = dateInput.value;
        if (!routeData || !routeData.coords || !dateVal) { weatherEl.style.display = "none"; return; }

        // Forecast API pokrývá dnešek až ~+15 dní
        const d = new Date(dateVal + "T12:00:00");
        const today = new Date(); today.setHours(0, 0, 0, 0);
        const diffDays = Math.round((d - today) / 86400000);
        if (isNaN(diffDays) || diffDays < 0 || diffDays > 15) { weatherEl.style.display = "none"; return; }

        const mid = routeData.coords[Math.floor(routeData.coords.length / 2)];
        try {
            const url = "https://api.open-meteo.com/v1/forecast"
                + "?latitude=" + mid[0].toFixed(4) + "&longitude=" + mid[1].toFixed(4)
                + "&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,"
                + "precipitation_probability_max,wind_speed_10m_max,sunrise,sunset"
                + "&timezone=auto&start_date=" + dateVal + "&end_date=" + dateVal;
            const res  = await fetch(url);
            const data = await res.json();
            const dd = data && data.daily;
            if (!dd || !dd.time || !dd.time.length) { weatherEl.style.display = "none"; return; }

            const sr = String(dd.sunrise[0] || "").slice(11, 16);
            const ss = String(dd.sunset[0]  || "").slice(11, 16);
            weatherEl.innerHTML =
                "<span class=\"pw-emoji\">" + wmoEmoji((dd.weather_code || [99])[0]) + "</span>" +
                "<span>🌡️ " + Math.round(dd.temperature_2m_min[0]) + "–" + Math.round(dd.temperature_2m_max[0]) + " °C</span>" +
                "<span>🌧️ " + (i18n.precip || "srážky") + ": " + dd.precipitation_sum[0] + " mm (" + (dd.precipitation_probability_max[0] ?? "–") + " %)</span>" +
                "<span>💨 " + (i18n.wind || "vítr") + ": " + Math.round(dd.wind_speed_10m_max[0]) + " km/h</span>" +
                "<span>🌅 " + (i18n.sunrise || "východ") + " " + sr + " · 🌇 " + (i18n.sunset || "západ") + " " + ss + "</span>";
            weatherEl.style.display = "flex";
        } catch (err) {
            if (window.GPX_DEBUG) console.error("planner weather:", err);
            weatherEl.style.display = "none";
        }
    }

    dateInput.addEventListener("change", loadWeather);

    // ===== Uložené plány (save / load / delete) =====
    const PLAN_PLACEHOLDER = planListEl.options.length ? planListEl.options[0].textContent : "—";

    async function refreshPlansList(selectId) {
        try {
            const res  = await fetch("api/planner/list.php");
            const data = await res.json();
            if (!data.ok) return;
            const cur = selectId !== undefined ? String(selectId) : planListEl.value;
            planListEl.innerHTML = "";
            const opt0 = document.createElement("option");
            opt0.value = ""; opt0.textContent = PLAN_PLACEHOLDER;
            planListEl.appendChild(opt0);
            (data.plans || []).forEach(p => {
                const o = document.createElement("option");
                o.value = String(p.id);
                const parts = [p.name];
                if (p.plan_date) parts.push(p.plan_date);
                if (p.length_m)  parts.push(fmtKm(p.length_m));
                o.textContent = parts.join(" · ");
                planListEl.appendChild(o);
            });
            if (cur && Array.prototype.some.call(planListEl.options, o => o.value === cur)) {
                planListEl.value = cur;
            } else {
                planListEl.value = "";
            }
            updateButtons();
        } catch (err) {
            if (window.GPX_DEBUG) console.error("planner list:", err);
        }
    }

    async function savePlan() {
        if (!(routeData && routeData.coords)) return;
        const name = (nameInput.value || "").trim();
        if (!name) { alert(i18n.nameNeeded || "Zadej název plánu."); nameInput.focus(); return; }

        const fd = new FormData();
        fd.append("_csrf_token", CSRF);
        fd.append("id", String(currentPlanId || 0));
        fd.append("name", name);
        fd.append("profile", profileSel.value || "foot_hiking");
        fd.append("plan_date", dateInput.value || "");
        fd.append("waypoints", JSON.stringify(waypoints.map(w => [+w.lat.toFixed(6), +w.lng.toFixed(6)])));
        fd.append("geometry", JSON.stringify(routeData.coords.map(c => [+c[0].toFixed(6), +c[1].toFixed(6)])));
        if (routeData.length_m   != null) fd.append("length_m",   String(Math.round(routeData.length_m)));
        if (routeData.duration_s != null) fd.append("duration_s", String(Math.round(routeData.duration_s)));
        if (routeData.ascent     != null) fd.append("ascent",     String(routeData.ascent));
        if (routeData.descent    != null) fd.append("descent",    String(routeData.descent));

        try {
            const res  = await fetch("api/planner/save.php", { method: "POST", body: fd });
            const data = await res.json();
            if (data.ok) {
                currentPlanId = data.id;
                setStatus(i18n.saved || "Uloženo.", "done");
                refreshPlansList(data.id);
            } else {
                setStatus((i18n.saveError || "Uložení selhalo") + ": " + (data.error || "?"), "error");
            }
        } catch (err) {
            setStatus((i18n.saveError || "Uložení selhalo") + ".", "error");
        }
    }

    async function loadPlan(id) {
        try {
            const res  = await fetch("api/planner/get.php?id=" + encodeURIComponent(id));
            const data = await res.json();
            if (!data.ok || !data.plan) return;
            const p = data.plan;

            waypoints.forEach(w => map.removeLayer(w.marker));
            waypoints = [];
            clearRoute();

            restoring = true;
            (p.waypoints || []).forEach(c => addWaypoint({ lat: c[0], lng: c[1] }, true));
            restoring = false;

            nameInput.value  = p.name || "";
            profileSel.value = p.profile || "foot_hiking";
            dateInput.value  = p.plan_date || "";
            currentPlanId    = p.id;

            if (Array.isArray(p.geometry) && p.geometry.length > 1) {
                // Geometrie je uložená → kreslíme rovnou, routing se NEvolá (šetří kvótu)
                routeData = {
                    ok: true, coords: p.geometry,
                    length_m: p.length_m || 0, duration_s: p.duration_s || 0,
                    ascent: p.ascent, descent: p.descent
                };
                routeLine = L.polyline(p.geometry, { color: "#1565c0", weight: 4, opacity: 0.85 }).addTo(map);
                document.getElementById("planDist").textContent = fmtKm(routeData.length_m);
                document.getElementById("planDur").textContent  = "~" + fmtDur(routeData.duration_s);
                document.getElementById("planAsc").textContent  = p.ascent  != null ? p.ascent  + " m" : "–";
                document.getElementById("planDesc").textContent = p.descent != null ? p.descent + " m" : "–";
                statsEl.style.display = "flex";
                updatePersonalPace(p.ascent || 0);
                loadElevation(p.geometry, ++computeSeq);
                loadWeather();
                map.fitBounds(L.latLngBounds(p.geometry), { padding: [40, 40] });
            } else if (waypoints.length >= 2) {
                scheduleCompute();   // starý plán bez geometrie → přepočítat
            }
            refreshMarkers();
            updateButtons();
            setStatus(i18n.loaded || "Načteno.", "done");
        } catch (err) {
            setStatus((i18n.error || "Chyba") + ".", "error");
        }
    }

    async function deletePlan() {
        const id = planListEl.value;
        if (!id) return;
        if (!confirm(i18n.confirmDel || "Opravdu smazat?")) return;

        const fd = new FormData();
        fd.append("_csrf_token", CSRF);
        fd.append("id", id);
        try {
            const res  = await fetch("api/planner/delete.php", { method: "POST", body: fd });
            const data = await res.json();
            if (data.ok) {
                if (String(currentPlanId) === String(id)) currentPlanId = 0;
                setStatus(i18n.deleted || "Smazáno.", "done");
                refreshPlansList("");
            }
        } catch (err) {
            if (window.GPX_DEBUG) console.error("planner delete:", err);
        }
    }

    btnSave.addEventListener("click", savePlan);
    btnDelete.addEventListener("click", deletePlan);
    planListEl.addEventListener("change", () => {
        updateButtons();
        if (planListEl.value) loadPlan(planListEl.value);
    });

    refreshPlansList();

    // ===== Klik do mapy = nový waypoint =====
    map.on("click", e => addWaypoint(e.latlng));

    // ===== Zpět / Vyčistit =====
    btnUndo.addEventListener("click", () => {
        const w = waypoints[waypoints.length - 1];
        if (w) removeWaypoint(w);
    });

    btnClear.addEventListener("click", () => {
        waypoints.forEach(w => map.removeLayer(w.marker));
        waypoints = [];
        currentPlanId = 0;
        if (planListEl) planListEl.value = "";
        if (weatherEl) weatherEl.style.display = "none";
        refreshMarkers();
        clearRoute();
        updateButtons();
        setStatus(i18n.clickHint || "", "");
    });

    // ===== Export GPX (Garmin: import v Garmin Connect → Courses, nebo USB) =====
    function xmlEsc(s) {
        return String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;")
            .replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&apos;");
    }

    btnExport.addEventListener("click", () => {
        if (!(routeData && routeData.coords && routeData.coords.length > 1)) return;

        const name = (nameInput.value || "").trim() || ("Plán " + new Date().toISOString().slice(0, 10));
        const now  = new Date().toISOString();

        let gpx = '<?xml version="1.0" encoding="UTF-8"?>\n'
            + '<gpx version="1.1" creator="GPX Manager — Plánovač" xmlns="http://www.topografix.com/GPX/1/1">\n'
            + '<metadata><name>' + xmlEsc(name) + '</name><time>' + now + '</time></metadata>\n'
            + '<trk><name>' + xmlEsc(name) + '</name><trkseg>\n';
        routeData.coords.forEach(c => {
            gpx += '<trkpt lat="' + c[0].toFixed(6) + '" lon="' + c[1].toFixed(6) + '"></trkpt>\n';
        });
        gpx += '</trkseg></trk>\n</gpx>\n';

        const fileStem = name.replace(/[^\wÀ-ſ -]+/g, "").replace(/\s+/g, "_").substring(0, 60) || "plan";
        const blob = new Blob([gpx], { type: "application/gpx+xml" });
        const a = document.createElement("a");
        a.href = URL.createObjectURL(blob);
        a.download = fileStem + ".gpx";
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(() => URL.revokeObjectURL(a.href), 5000);
    });

    updateButtons();
});
