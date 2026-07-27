/**
 * ===========================================================
 *  GPX Manager – Porovnání s plánem (detail trasy)
 *  Překryje reálnou trasu uloženým plánem z Plánovače a vyhodnotí,
 *  kde a o kolik ses od plánu odchýlil.
 *  Zapíná se v Administraci → Volitelné funkce.
 *
 *  Do detail-map.js nezasahuje — kreslí do sdílené mapy stejně
 *  jako detail-replay.js.
 * ===========================================================
 */

if (window.GPX_DEBUG) console.log("🗺️ detail-plan.js načten");

document.addEventListener("gpxDataReady", (ev) => {
    const wrap = document.getElementById("plan-compare");
    if (!wrap || typeof map === "undefined" || !map) return;

    const cfg  = window.gpxDetailData || {};
    const i18n = cfg.planI18n || {};

    // ===== Body reálné trasy =====
    const xml = new DOMParser().parseFromString(ev.detail.xmlText, "application/xml");
    const trkpts = Array.from(xml.getElementsByTagName("trkpt"));
    if (trkpts.length < 2) return;

    const trk = trkpts.map(pt => [
        parseFloat(pt.getAttribute("lat")),
        parseFloat(pt.getAttribute("lon"))
    ]).filter(p => !isNaN(p[0]) && !isNaN(p[1]));
    if (trk.length < 2) return;

    // ===== Elementy =====
    const btn        = document.getElementById("planToggle");
    const selWrap    = document.getElementById("planSelectWrap");
    const sel        = document.getElementById("planSelect");
    const tolWrap    = document.getElementById("planTolWrap");
    const tol        = document.getElementById("planTol");
    const statusEl   = document.getElementById("planStatus");
    const legendEl   = document.getElementById("planLegend");

    let plans = [];          // seznam z api/planner/list.php
    let planOn = false;
    let planLayer = null;    // čárkovaná linka plánu
    let devLayer  = null;    // červené úseky mimo plán
    let maxMarker = null;    // značka v místě největší odchylky
    let loadedId  = null;    // id plánu, jehož geometrie je načtená
    let planGeom  = null;    // [[lat,lon], …]
    let index     = null;    // mřížkový index bodů plánu

    /* ============ Geometrie ============ */
    // Lokální rovinná projekce (equirectangular) kolem těžiště trasy — pro
    // vzdálenosti do pár desítek km je odchylka zanedbatelná a počítá se rychle.
    const R = 6371000;
    let lat0 = trk.reduce((s, p) => s + p[0], 0) / trk.length;
    const kx = Math.cos(lat0 * Math.PI / 180) * R * Math.PI / 180;
    const ky = R * Math.PI / 180;
    const toXY = p => [p[1] * kx, p[0] * ky];

    /** Vzdálenost bodu od úsečky (vše v metrech). */
    function distToSeg(px, py, ax, ay, bx, by) {
        const dx = bx - ax, dy = by - ay;
        const len2 = dx * dx + dy * dy;
        let t = len2 > 0 ? ((px - ax) * dx + (py - ay) * dy) / len2 : 0;
        t = t < 0 ? 0 : (t > 1 ? 1 : t);
        const qx = ax + t * dx, qy = ay + t * dy;
        return Math.hypot(px - qx, py - qy);
    }

    /**
     * Mřížkový index bodů plánu. Bez něj by porovnání bylo O(N×M) — u trasy
     * s 1500 body a hustého plánu to jsou miliony výpočtů na každou změnu
     * tolerance. S mřížkou se prohledává jen okolí bodu.
     */
    function buildIndex(xy, cell) {
        const g = new Map();
        for (let i = 0; i < xy.length; i++) {
            const k = Math.floor(xy[i][0] / cell) + "," + Math.floor(xy[i][1] / cell);
            let b = g.get(k);
            if (!b) { b = []; g.set(k, b); }
            b.push(i);
        }
        return { g, cell, xy };
    }

    /** Nejmenší vzdálenost bodu od linie plánu (v metrech). */
    function distToPlan(px, py) {
        const { g, cell, xy } = index;
        const cx = Math.floor(px / cell), cy = Math.floor(py / cell);
        let best = Infinity;
        // Kroužky buněk zvětšujeme, dokud nemáme jistotu, že blíž už nic není
        for (let ring = 0; ring < 60; ring++) {
            for (let ax = cx - ring; ax <= cx + ring; ax++) {
                for (let ay = cy - ring; ay <= cy + ring; ay++) {
                    // uvnitř kroužku procházíme jen jeho okraj
                    if (ring > 0 && Math.abs(ax - cx) !== ring && Math.abs(ay - cy) !== ring) continue;
                    const b = g.get(ax + "," + ay);
                    if (!b) continue;
                    for (const i of b) {
                        // úsečky sousedící s bodem — přesnější než vzdálenost k vrcholu
                        if (i > 0) {
                            const d = distToSeg(px, py, xy[i - 1][0], xy[i - 1][1], xy[i][0], xy[i][1]);
                            if (d < best) best = d;
                        }
                        if (i < xy.length - 1) {
                            const d = distToSeg(px, py, xy[i][0], xy[i][1], xy[i + 1][0], xy[i + 1][1]);
                            if (d < best) best = d;
                        }
                    }
                }
            }
            // Nalezené minimum je jisté, až když je menší než prohledaný poloměr
            if (best <= ring * cell) break;
        }
        return best;
    }

    /** Délka polyline v metrech. */
    function lineLength(pts) {
        let s = 0;
        for (let i = 1; i < pts.length; i++) {
            s += window.GpxGeo.haversine(pts[i - 1][0], pts[i - 1][1], pts[i][0], pts[i][1]);
        }
        return s;
    }

    function fmtKm(m)  { return (m / 1000).toFixed(2).replace(".", ",") + " km"; }
    function fmtM(m)   { return Math.round(m) + " m"; }

    /* ============ Vykreslení ============ */
    function clearLayers() {
        if (planLayer) { map.removeLayer(planLayer); planLayer = null; }
        if (devLayer)  { map.removeLayer(devLayer);  devLayer  = null; }
        if (maxMarker) { map.removeLayer(maxMarker); maxMarker = null; }
    }

    function drawPlan() {
        if (!planGeom) return;
        clearLayers();

        // Plán jde pod reálnou trasu (bringToBack), aby zůstala čitelná
        planLayer = L.polyline(planGeom, {
            color: "#7c3aed", weight: 5, opacity: 0.75,
            dashArray: "10 8", lineCap: "round", interactive: false
        }).addTo(map);
        planLayer.bringToBack();

        // Vzorek v legendě sladit se skutečnou barvou trasy (každá trasa ji má vlastní)
        const swatch = document.querySelector(".plan-swatch-real");
        if (swatch) {
            let trackColor = null;
            map.eachLayer(l => {
                if (trackColor) return;
                if (l instanceof L.Polyline && !(l instanceof L.Polygon)
                    && l !== planLayer && l !== devLayer && l.options.color) {
                    trackColor = l.options.color;
                }
            });
            if (trackColor) swatch.style.borderTopColor = trackColor;
        }

        analyse();
    }

    // Krátký návrat na plán uprostřed objížďky ji nedělí na dvě
    const MERGE_GAP_M  = 80;
    // Kratší vybočení je kolísání GPS nebo křížení plánu, ne odbočka
    const MIN_DETOUR_M = 50;

    /** Spočítá odchylky a obarví úseky, kde jsi šel mimo plán. */
    function analyse() {
        if (!index) return;
        const limit = parseInt(tol ? tol.value : "25", 10);

        // Vzdálenost od plánu pro každý bod + kumulativní délka trasy.
        // Statistiky se váží DÉLKOU, ne počtem bodů — při zastávce se body
        // hromadí na jednom místě a podíl „podle plánu" by byl zkreslený.
        const dists = new Array(trk.length);
        const cum   = new Array(trk.length);
        let max = 0, maxIdx = 0;
        cum[0] = 0;
        for (let i = 0; i < trk.length; i++) {
            const [x, y] = toXY(trk[i]);
            const d = distToPlan(x, y);
            dists[i] = d;
            if (d > max) { max = d; maxIdx = i; }
            if (i > 0) {
                cum[i] = cum[i - 1] + window.GpxGeo.haversine(
                    trk[i - 1][0], trk[i - 1][1], trk[i][0], trk[i][1]);
            }
        }
        const trkLen = cum[trk.length - 1];

        // Úsek mezi dvěma body je „mimo plán", když je mimo aspoň jeden konec
        let offLen = 0, devSum = 0;
        const runs = [];
        let start = null;
        for (let i = 1; i < trk.length; i++) {
            const segLen = cum[i] - cum[i - 1];
            const off = dists[i - 1] > limit || dists[i] > limit;
            devSum += ((dists[i - 1] + dists[i]) / 2) * segLen;
            if (off) {
                offLen += segLen;
                if (start === null) start = i - 1;
            } else if (start !== null) {
                runs.push([start, i]);
                start = null;
            }
        }
        if (start !== null) runs.push([start, trk.length - 1]);

        // Sloučit úseky oddělené jen krátkým návratem na plán
        const merged = [];
        for (const r of runs) {
            const last = merged[merged.length - 1];
            if (last && cum[r[0]] - cum[last[1]] < MERGE_GAP_M) last[1] = r[1];
            else merged.push([r[0], r[1]]);
        }
        // Zahodit vybočení kratší než minimum (šum GPS, křížení plánu)
        const detours = merged.filter(r => cum[r[1]] - cum[r[0]] >= MIN_DETOUR_M);

        if (devLayer) { map.removeLayer(devLayer); devLayer = null; }
        if (detours.length) {
            devLayer = L.polyline(detours.map(r => trk.slice(r[0], r[1] + 1)), {
                color: "#dc2626", weight: 5, opacity: 0.95,
                lineCap: "round", interactive: false
            }).addTo(map);
        }

        const onPlanPct = trkLen > 0 ? Math.round(100 * (trkLen - offLen) / trkLen) : 0;
        const planLen   = lineLength(planGeom);
        const avgDev    = trkLen > 0 ? devSum / trkLen : 0;
        const diff      = trkLen - planLen;

        if (statusEl) {
            statusEl.innerHTML =
                "<strong>" + onPlanPct + " %</strong> " + (i18n.onPlan || "trasy podle plánu")
                + " · " + (i18n.detours || "odboček") + ": <strong>" + detours.length + "</strong>"
                + (offLen > 0 ? " (" + fmtKm(offLen) + ")" : "")
                + " · " + (i18n.maxDev || "nejdál od plánu") + ": <strong>" + fmtM(max) + "</strong>"
                + " · " + (i18n.avgDev || "průměrně") + ": " + fmtM(avgDev)
                + "<br>" + (i18n.planLen || "plán") + ": " + fmtKm(planLen)
                + " · " + (i18n.realLen || "realita") + ": " + fmtKm(trkLen)
                + " (" + (diff >= 0 ? "+" : "−") + fmtM(Math.abs(diff)) + ")";
        }
        if (legendEl) legendEl.style.display = "";

        // Značka v místě největší odchylky — ať ji na mapě rovnou najdeš
        if (maxMarker) { map.removeLayer(maxMarker); maxMarker = null; }
        if (max > limit) {
            maxMarker = L.circleMarker(trk[maxIdx], {
                radius: 7, color: "#dc2626", weight: 2,
                fillColor: "#dc2626", fillOpacity: 0.35
            }).addTo(map);
            maxMarker.bindTooltip((i18n.maxDev || "nejdál od plánu") + ": " + fmtM(max),
                { direction: "top" });
        }
    }

    /* ============ Načítání ============ */
    async function loadPlanGeometry(id) {
        if (loadedId === id && planGeom) { drawPlan(); return; }
        if (statusEl) statusEl.textContent = i18n.loading || "…";
        try {
            const res = await fetch("api/planner/get.php?id=" + encodeURIComponent(id));
            const d = await res.json();
            const g = d && d.plan && d.plan.geometry;
            if (!Array.isArray(g) || g.length < 2) {
                if (statusEl) statusEl.textContent = i18n.noGeom || "";
                planGeom = null; index = null;
                return;
            }
            planGeom = g.filter(p => Array.isArray(p) && p.length >= 2
                                     && !isNaN(p[0]) && !isNaN(p[1]));
            loadedId = id;
            // Buňka 60 m — kompromis mezi počtem buněk a velikostí prohledávaného okolí
            index = buildIndex(planGeom.map(toXY), 60);
            drawPlan();
        } catch (err) {
            if (window.GPX_DEBUG) console.error("plan overlay:", err);
            if (statusEl) statusEl.textContent = i18n.error || "";
        }
    }

    /** Nejpravděpodobnější plán k této trase: shoda data, jinak nejbližší těžiště. */
    function bestPlanId() {
        const usable = plans.filter(p => p.has_geometry);
        if (!usable.length) return null;

        const trackDate = (cfg.trackDateStart || "").slice(0, 10);
        const sameDay = usable.filter(p => p.plan_date && p.plan_date === trackDate);
        const pool = sameDay.length ? sameDay : usable;

        const c = [lat0, trk.reduce((s, p) => s + p[1], 0) / trk.length];
        let best = pool[0], bestD = Infinity;
        for (const p of pool) {
            if (p.lat === null || p.lon === null) continue;
            const d = window.GpxGeo.haversine(c[0], c[1], p.lat, p.lon);
            if (d < bestD) { bestD = d; best = p; }
        }
        return best ? best.id : null;
    }

    async function loadPlanList() {
        try {
            const res = await fetch("api/planner/list.php?with_pos=1");
            const d = await res.json();
            plans = (d && d.plans || []).filter(p => p.has_geometry);
        } catch (err) {
            if (window.GPX_DEBUG) console.error("plan list:", err);
            plans = [];
        }
        if (!plans.length) return false;

        sel.innerHTML = "";
        plans.forEach(p => {
            const o = document.createElement("option");
            o.value = p.id;
            o.textContent = p.name + (p.plan_date ? " (" + p.plan_date + ")" : "");
            sel.appendChild(o);
        });
        const pick = bestPlanId();
        if (pick) sel.value = String(pick);
        return true;
    }

    /* ============ Ovládání ============ */
    if (btn) {
        btn.addEventListener("click", async () => {
            planOn = !planOn;
            btn.classList.toggle("plan-btn-active", planOn);
            try { localStorage.setItem("gpx_plan_overlay", planOn ? "1" : "0"); } catch (e) {}

            if (!planOn) {
                clearLayers();
                if (selWrap)  selWrap.style.display  = "none";
                if (tolWrap)  tolWrap.style.display  = "none";
                if (legendEl) legendEl.style.display = "none";
                if (statusEl) statusEl.textContent   = "";
                return;
            }
            if (!plans.length) {
                const ok = await loadPlanList();
                if (!ok) {
                    if (statusEl) statusEl.textContent = i18n.noPlans || "";
                    return;
                }
            }
            if (selWrap) selWrap.style.display = "";
            if (tolWrap) tolWrap.style.display = "";
            loadPlanGeometry(parseInt(sel.value, 10));
        });
    }

    if (sel) sel.addEventListener("change", () => loadPlanGeometry(parseInt(sel.value, 10)));
    if (tol) tol.addEventListener("change", () => { if (planOn && planGeom) analyse(); });

    // Panel se ukáže jen když vůbec existuje nějaký plán s geometrií
    (async () => {
        const has = await loadPlanList();
        if (!has) return;
        wrap.style.display = "";

        let remembered = "0";
        try { remembered = localStorage.getItem("gpx_plan_overlay") || "0"; } catch (e) {}
        if (remembered !== "1" || !btn) return;

        // Volba se pamatuje napříč trasami, ale sama se zapne jen když je
        // předvybraný plán opravdu z okolí této trasy — jinak by u výletu
        // v jiném kraji naskočilo nesmyslné porovnání s 0 % shody.
        const p = plans.find(x => String(x.id) === sel.value);
        if (!p || p.lat === null) return;
        const lonC = trk.reduce((s, q) => s + q[1], 0) / trk.length;
        if (window.GpxGeo.haversine(lat0, lonC, p.lat, p.lon) <= 25000) btn.click();
    })();
});
