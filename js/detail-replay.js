/**
 * ===========================================================
 *  GPX Manager – Přehrávač výšlapu (detail trasy)
 *  Animovaný turista po trkpt bodech dle časových značek,
 *  počasí „u turisty" (Open-Meteo archiv/forecast) a průhledné
 *  srážkové pole interpolované z mřížky bodů kolem trasy.
 *  Zapíná se v Administraci → Volitelné funkce.
 * ===========================================================
 */

if (window.GPX_DEBUG) console.log("▶️ detail-replay.js načten");

document.addEventListener("gpxDataReady", (ev) => {
    const panel = document.getElementById("replay-panel");
    if (!panel || typeof map === "undefined" || !map) return;

    const cfg   = window.gpxDetailData || {};
    const flags = cfg.replayFlags || {};
    const i18n  = cfg.replayI18n || {};

    // ===== Parsování trkpt bodů (plné rozlišení, vlastní kopie) =====
    const xml = new DOMParser().parseFromString(ev.detail.xmlText, "application/xml");
    const trkpts = Array.from(xml.getElementsByTagName("trkpt"));
    if (trkpts.length < 2) return;

    const lat = [], lon = [], ele = [], tMs = [], distM = [0];
    let timeCount = 0;
    trkpts.forEach((pt, i) => {
        lat.push(parseFloat(pt.getAttribute("lat")));
        lon.push(parseFloat(pt.getAttribute("lon")));
        ele.push(parseFloat((pt.getElementsByTagName("ele")[0] || {}).textContent || "0"));
        const iso = (pt.getElementsByTagName("time")[0] || {}).textContent || "";
        const ms  = iso ? Date.parse(iso) : NaN;
        tMs.push(isNaN(ms) ? null : ms);
        if (!isNaN(ms)) timeCount++;
        if (i > 0) {
            distM.push(distM[i - 1] + window.GpxGeo.haversine(lat[i - 1], lon[i - 1], lat[i], lon[i]));
        }
    });

    // Bez časů → syntetické časy z vzdálenosti (4 km/h), přehrává se „po trase"
    const hasTimes = timeCount >= Math.max(2, trkpts.length * 0.5);
    if (!hasTimes) {
        const speedMs = 4000 / 3600000; // 4 km/h v m/ms
        const t0 = Date.now();
        for (let i = 0; i < trkpts.length; i++) tMs[i] = t0 + distM[i] / speedMs;
        const note = document.getElementById("rpNote");
        if (note) { note.textContent = i18n.noTimes || ""; note.style.display = "block"; }
    } else {
        // Chybějící časy doplnit interpolací mezi sousedy (ojedinělé díry)
        for (let i = 0; i < tMs.length; i++) {
            if (tMs[i] === null) {
                let p = i - 1; while (p >= 0 && tMs[p] === null) p--;
                let n = i + 1; while (n < tMs.length && tMs[n] === null) n++;
                if (p >= 0 && n < tMs.length) {
                    const f = (distM[i] - distM[p]) / Math.max(1, distM[n] - distM[p]);
                    tMs[i] = tMs[p] + f * (tMs[n] - tMs[p]);
                } else if (p >= 0) tMs[i] = tMs[p];
                else if (n < tMs.length) tMs[i] = tMs[n];
            }
        }
    }

    const t0 = tMs[0], tEnd = tMs[tMs.length - 1];
    if (!(tEnd > t0)) return;

    panel.style.display = "block";

    // ===== Elementy =====
    const btnPlay   = document.getElementById("rpPlay");
    const btnBackKm = document.getElementById("rpBackKm");
    const btnFwdKm  = document.getElementById("rpFwdKm");
    const btnBackMin= document.getElementById("rpBackMin");
    const btnFwdMin = document.getElementById("rpFwdMin");
    const speedSel  = document.getElementById("rpSpeed");
    const slider    = document.getElementById("rpSlider");
    const outTime   = document.getElementById("rpTime");
    const outDist   = document.getElementById("rpDist");
    const outEle    = document.getElementById("rpEle");
    const weatherEl = document.getElementById("rpWeather");

    // ===== Stav přehrávače =====
    let tCur = t0;
    let playing = false;
    let timer = null;
    let walker = null;

    function fmtClock(ms) {
        const d = new Date(ms);
        return hasTimes
            ? d.toLocaleTimeString("cs-CZ", { hour: "2-digit", minute: "2-digit" })
            : "–";
    }

    // Binární hledání indexu podle času
    function idxByTime(t) {
        let lo = 0, hi = tMs.length - 1;
        while (lo < hi) {
            const mid = (lo + hi + 1) >> 1;
            if (tMs[mid] <= t) lo = mid; else hi = mid - 1;
        }
        return lo;
    }

    function posAt(t) {
        const i = idxByTime(t);
        const j = Math.min(i + 1, tMs.length - 1);
        const f = (j > i && tMs[j] > tMs[i]) ? (t - tMs[i]) / (tMs[j] - tMs[i]) : 0;
        return {
            lat: lat[i] + (lat[j] - lat[i]) * f,
            lon: lon[i] + (lon[j] - lon[i]) * f,
            ele: ele[i] + (ele[j] - ele[i]) * f,
            dist: distM[i] + (distM[j] - distM[i]) * f,
            idx: i
        };
    }

    function ensureWalker(p) {
        if (walker) { walker.setLatLng([p.lat, p.lon]); return; }
        const icon = L.divIcon({
            className: "",
            html: "<div class=\"replay-walker\">🚶</div>",
            iconSize: [30, 30], iconAnchor: [15, 15]
        });
        walker = L.marker([p.lat, p.lon], { icon, zIndexOffset: 1500, keyboard: false }).addTo(map);
    }

    function update() {
        tCur = Math.min(Math.max(tCur, t0), tEnd);
        const p = posAt(tCur);
        ensureWalker(p);
        outTime.textContent = fmtClock(tCur);
        outDist.textContent = (p.dist / 1000).toFixed(2).replace(".", ",") + " km";
        outEle.textContent  = Math.round(p.ele) + " m";
        slider.value = Math.round(((tCur - t0) / (tEnd - t0)) * 1000);
        updateWeather(p);
        updateRadar();
        if (tCur >= tEnd && playing) pause();
    }

    function play() {
        playing = true;
        btnPlay.textContent = "⏸";
        btnPlay.title = i18n.pause || "Pauza";
        if (tCur >= tEnd) tCur = t0;
        const stepMs = 200;
        timer = setInterval(() => {
            tCur += stepMs * parseFloat(speedSel.value || "60");
            update();
        }, stepMs);
        if (flags.weather) prefetchWeather();
    }

    function pause() {
        playing = false;
        btnPlay.textContent = "▶";
        btnPlay.title = i18n.play || "Přehrát";
        clearInterval(timer);
    }

    btnPlay.addEventListener("click", () => playing ? pause() : play());
    btnBackMin.addEventListener("click", () => { tCur -= 5 * 60000; update(); });
    btnFwdMin.addEventListener("click",  () => { tCur += 5 * 60000; update(); });

    // Skok ±1 km: najít čas na vzdálenosti dist±1000 m
    function timeAtDist(d) {
        d = Math.min(Math.max(d, 0), distM[distM.length - 1]);
        let lo = 0, hi = distM.length - 1;
        while (lo < hi) {
            const mid = (lo + hi + 1) >> 1;
            if (distM[mid] <= d) lo = mid; else hi = mid - 1;
        }
        const j = Math.min(lo + 1, distM.length - 1);
        const f = (j > lo && distM[j] > distM[lo]) ? (d - distM[lo]) / (distM[j] - distM[lo]) : 0;
        return tMs[lo] + (tMs[j] - tMs[lo]) * f;
    }
    btnBackKm.addEventListener("click", () => { tCur = timeAtDist(posAt(tCur).dist - 1000); update(); });
    btnFwdKm.addEventListener("click",  () => { tCur = timeAtDist(posAt(tCur).dist + 1000); update(); });

    slider.addEventListener("input", () => {
        tCur = t0 + (parseInt(slider.value, 10) / 1000) * (tEnd - t0);
        update();
        if (flags.weather) prefetchWeather();
    });

    // ===== Open-Meteo helpers =====
    function dateStr(ms) {
        const d = new Date(ms);
        return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0")
            + "-" + String(d.getDate()).padStart(2, "0");
    }

    // Archiv (ERA5) má zpoždění ~5 dní; novější výlety jdou přes forecast API
    function apiBase() {
        const ageDays = (Date.now() - t0) / 86400000;
        return ageDays < 85
            ? "https://api.open-meteo.com/v1/forecast"
            : "https://archive-api.open-meteo.com/v1/archive";
    }

    // Najde index hodiny v poli ISO časů (lokální čas dle timezone=auto)
    function hourIndex(times, t) {
        const iso = new Date(t);
        const key = dateStr(t) + "T" + String(iso.getHours()).padStart(2, "0");
        for (let i = 0; i < times.length; i++) {
            if (times[i].startsWith(key)) return i;
        }
        return -1;
    }

    // ===== Počasí „u turisty" (fáze B) =====
    let wxData = null;      // [{lat, lon, hourly}]
    let wxState = "idle";   // idle | loading | ready | error

    function sampleIndices(n) {
        const total = distM[distM.length - 1];
        return [0, 0.25, 0.5, 0.75, 1].map(f => {
            const target = total * f;
            let best = 0;
            for (let i = 0; i < distM.length; i += Math.max(1, Math.floor(distM.length / 200))) {
                if (Math.abs(distM[i] - target) < Math.abs(distM[best] - target)) best = i;
            }
            return best;
        });
    }

    async function prefetchWeather() {
        if (!flags.weather || !hasTimes || wxState !== "idle" || !weatherEl) return;
        wxState = "loading";
        try {
            const idxs = sampleIndices(5);
            const lats = idxs.map(i => lat[i].toFixed(4)).join(",");
            const lons = idxs.map(i => lon[i].toFixed(4)).join(",");
            const url = apiBase()
                + "?latitude=" + lats + "&longitude=" + lons
                + "&hourly=temperature_2m,precipitation,wind_speed_10m,cloud_cover"
                + "&timezone=auto&start_date=" + dateStr(t0) + "&end_date=" + dateStr(tEnd);
            const res  = await fetch(url);
            const data = await res.json();
            const arr  = Array.isArray(data) ? data : [data];
            wxData = arr.map((d, k) => ({ i: idxs[k], hourly: d.hourly }));
            wxState = "ready";
            update();
        } catch (err) {
            if (window.GPX_DEBUG) console.error("replay weather:", err);
            wxState = "error";
        }
    }

    function updateWeather(p) {
        if (!flags.weather || !weatherEl) return;
        if (wxState !== "ready" || !wxData) { weatherEl.style.display = "none"; return; }
        // nejbližší vzorek podle indexu bodu
        let best = wxData[0];
        wxData.forEach(s => { if (Math.abs(s.i - p.idx) < Math.abs(best.i - p.idx)) best = s; });
        const h = best.hourly;
        if (!h || !h.time) { weatherEl.style.display = "none"; return; }
        const hi = hourIndex(h.time, tCur);
        if (hi < 0) { weatherEl.style.display = "none"; return; }
        const tC = h.temperature_2m ? h.temperature_2m[hi] : null;
        const pr = h.precipitation ? h.precipitation[hi] : null;
        const wi = h.wind_speed_10m ? h.wind_speed_10m[hi] : null;
        const cl = h.cloud_cover ? h.cloud_cover[hi] : null;
        if (tC === null || tC === undefined) { weatherEl.style.display = "none"; return; }
        const rain = (pr !== null && pr >= 0.1) ? ("🌧️ " + pr.toFixed(1) + " mm/h") : "🌂 0 mm";
        weatherEl.textContent = "🌡️ " + Math.round(tC) + " °C · " + rain
            + (wi !== null ? " · 💨 " + Math.round(wi) + " km/h" : "")
            + (cl !== null ? " · ☁️ " + Math.round(cl) + " %" : "");
        weatherEl.style.display = "block";
    }

    // ===== Srážkové pole (fáze C) =====
    const radarBtn     = document.getElementById("rpRadarToggle");
    const radarOpacity = document.getElementById("rpRadarOpacity");
    const radarOpWrap  = document.getElementById("rpRadarOpacityWrap");
    const radarStatus  = document.getElementById("rpRadarStatus");

    const GRID_N = 5;             // 5×5 bodů
    let radarOn = false;
    let radarData = null;         // {lats[], lons[], times[], vals[gridIdx][hourIdx]}
    let radarState = "idle";
    let radarOverlay = null;
    let radarHourShown = -2;
    const radarCanvas = document.createElement("canvas");
    radarCanvas.width = 240; radarCanvas.height = 240;

    function radarBounds() {
        let mnLa = Math.min(...lat), mxLa = Math.max(...lat);
        let mnLo = Math.min(...lon), mxLo = Math.max(...lon);
        const padLa = Math.max(0.18, (mxLa - mnLa) * 0.35);
        const padLo = Math.max(0.28, (mxLo - mnLo) * 0.35);
        return { s: mnLa - padLa, n: mxLa + padLa, w: mnLo - padLo, e: mxLo + padLo };
    }

    async function fetchRadar() {
        radarState = "loading";
        if (radarStatus) radarStatus.textContent = i18n.radarLoad || "Načítám…";
        try {
            const b = radarBounds();
            const gLat = [], gLon = [];
            for (let r = 0; r < GRID_N; r++) {
                for (let c = 0; c < GRID_N; c++) {
                    gLat.push((b.s + (b.n - b.s) * r / (GRID_N - 1)).toFixed(4));
                    gLon.push((b.w + (b.e - b.w) * c / (GRID_N - 1)).toFixed(4));
                }
            }
            const url = apiBase()
                + "?latitude=" + gLat.join(",") + "&longitude=" + gLon.join(",")
                + "&hourly=precipitation&timezone=auto"
                + "&start_date=" + dateStr(t0) + "&end_date=" + dateStr(tEnd);
            const res  = await fetch(url);
            const data = await res.json();
            const arr  = Array.isArray(data) ? data : [data];
            if (arr.length !== GRID_N * GRID_N || !arr[0].hourly) throw new Error("bad grid");
            radarData = {
                bounds: b,
                times: arr[0].hourly.time,
                vals: arr.map(d => d.hourly.precipitation || [])
            };
            radarState = "ready";
            const total = radarData.vals.reduce((s, v) => s + v.reduce((a, x) => a + (x || 0), 0), 0);
            if (radarStatus) radarStatus.textContent = total < 0.2 ? (i18n.radarNone || "") : "";
            radarHourShown = -2;
            updateRadar(true);
        } catch (err) {
            if (window.GPX_DEBUG) console.error("replay radar:", err);
            radarState = "error";
            if (radarStatus) radarStatus.textContent = i18n.radarErr || "Chyba.";
        }
    }

    // Barevná škála podle intenzity (mm/h) → [r,g,b,a 0..1]
    function precipColor(v) {
        if (v < 0.1) return null;
        if (v < 0.5)  return [110, 170, 255, 0.35];
        if (v < 1.5)  return [60, 130, 255, 0.5];
        if (v < 4)    return [20, 80, 230, 0.62];
        if (v < 8)    return [120, 40, 220, 0.7];
        return [200, 30, 180, 0.78];
    }

    function renderRadarCanvas(hi) {
        const ctx = radarCanvas.getContext("2d");
        ctx.clearRect(0, 0, 240, 240);
        if (hi < 0 || !radarData) return;
        const img = ctx.createImageData(240, 240);
        for (let y = 0; y < 240; y++) {
            // řádek 0 = sever = nejvyšší lat = grid řádek GRID_N-1
            const gy = (1 - y / 239) * (GRID_N - 1);
            const r0 = Math.floor(gy), r1 = Math.min(r0 + 1, GRID_N - 1), fy = gy - r0;
            for (let x = 0; x < 240; x++) {
                const gx = (x / 239) * (GRID_N - 1);
                const c0 = Math.floor(gx), c1 = Math.min(c0 + 1, GRID_N - 1), fx = gx - c0;
                const v00 = radarData.vals[r0 * GRID_N + c0][hi] || 0;
                const v01 = radarData.vals[r0 * GRID_N + c1][hi] || 0;
                const v10 = radarData.vals[r1 * GRID_N + c0][hi] || 0;
                const v11 = radarData.vals[r1 * GRID_N + c1][hi] || 0;
                const v = v00 * (1 - fx) * (1 - fy) + v01 * fx * (1 - fy)
                        + v10 * (1 - fx) * fy + v11 * fx * fy;
                const col = precipColor(v);
                if (col) {
                    const o = (y * 240 + x) * 4;
                    img.data[o] = col[0]; img.data[o + 1] = col[1];
                    img.data[o + 2] = col[2]; img.data[o + 3] = Math.round(col[3] * 255);
                }
            }
        }
        ctx.putImageData(img, 0, 0);
    }

    function updateRadar(force) {
        if (!flags.radar || !radarOn || radarState !== "ready" || !radarData) return;
        const hi = hourIndex(radarData.times, tCur);
        if (hi === radarHourShown && !force) return;
        radarHourShown = hi;
        renderRadarCanvas(hi);
        const url = radarCanvas.toDataURL();
        const b = radarData.bounds;
        if (!radarOverlay) {
            radarOverlay = L.imageOverlay(url, [[b.s, b.w], [b.n, b.e]], {
                opacity: parseInt(radarOpacity ? radarOpacity.value : "55", 10) / 100,
                interactive: false
            }).addTo(map);
        } else {
            radarOverlay.setUrl(url);
        }
    }

    if (radarBtn) {
        radarBtn.addEventListener("click", () => {
            radarOn = !radarOn;
            radarBtn.classList.toggle("rp-btn-active", radarOn);
            if (radarOpWrap) radarOpWrap.style.display = radarOn ? "" : "none";
            if (radarOn) {
                if (radarState === "idle" || radarState === "error") fetchRadar();
                else updateRadar(true);
            } else {
                if (radarOverlay) { map.removeLayer(radarOverlay); radarOverlay = null; radarHourShown = -2; }
                if (radarStatus) radarStatus.textContent = "";
            }
        });
    }
    if (radarOpacity) {
        radarOpacity.addEventListener("input", () => {
            if (radarOverlay) radarOverlay.setOpacity(parseInt(radarOpacity.value, 10) / 100);
        });
    }

    // ===== Výchozí stav =====
    update();
    if (flags.weather && hasTimes) prefetchWeather();
});
