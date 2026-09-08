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
    const linkBtn    = document.getElementById("planLinkBtn");   // jen admin

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

    /* ============ Automatický výběr plánu ============
       Plán se k trase přiřadí sám jen tehdy, když se opravdu překrývají.
       Dřív stačilo těžiště do 25 km — u trasy, kterou nikdo neplánoval, tak
       naskočilo porovnání s plánem odjinud a návštěvník viděl nesmysl. */
    const AUTO_TOL_M        = 40;      // co je „na plánu" při automatickém rozhodování
    const AUTO_MIN_PCT      = 60;      // aspoň tolik % délky trasy musí ležet na plánu
    const AUTO_NEAR_M       = 8000;    // předfiltr podle těžiště (ušetří stahování geometrie)
    const AUTO_MAX_CHECKS   = 3;       // kolik kandidátů se nejvýš ověřuje geometrií

    /** Podíl DÉLKY trasy, který leží do `limit` metrů od načteného plánu (v %). */
    function coveragePct(limit) {
        if (!index) return 0;
        let near = 0, total = 0, prevD = distToPlan(...toXY(trk[0]));
        for (let i = 1; i < trk.length; i++) {
            const [x, y] = toXY(trk[i]);
            const d = distToPlan(x, y);
            const segLen = window.GpxGeo.haversine(
                trk[i - 1][0], trk[i - 1][1], trk[i][0], trk[i][1]);
            total += segLen;
            if (prevD <= limit && d <= limit) near += segLen;
            prevD = d;
        }
        return total > 0 ? Math.round(100 * near / total) : 0;
    }

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
    /** Geometrie plánu ze serveru — bez kreslení, ať jde plán i jen ověřit. */
    async function fetchPlanGeom(id) {
        const res = await fetch("api/planner/get.php?id=" + encodeURIComponent(id));
        const d = await res.json();
        const g = d && d.plan && d.plan.geometry;
        if (!Array.isArray(g) || g.length < 2) return null;
        const pts = g.filter(p => Array.isArray(p) && p.length >= 2
                                  && !isNaN(p[0]) && !isNaN(p[1]));
        return pts.length >= 2 ? pts : null;
    }

    /** Načtený plán se stane tím aktivním (geometrie + index pro hledání). */
    function usePlanGeom(id, pts) {
        planGeom = pts;
        loadedId = id;
        // Buňka 60 m — kompromis mezi počtem buněk a velikostí prohledávaného okolí
        index = buildIndex(planGeom.map(toXY), 60);
    }

    async function loadPlanGeometry(id) {
        if (!id) { clearLayers(); return; }
        if (loadedId === id && planGeom) { drawPlan(); return; }
        if (statusEl) statusEl.textContent = i18n.loading || "…";
        try {
            const pts = await fetchPlanGeom(id);
            if (!pts) {
                if (statusEl) statusEl.textContent = i18n.noGeom || "";
                planGeom = null; index = null;
                return;
            }
            usePlanGeom(id, pts);
            drawPlan();
        } catch (err) {
            if (window.GPX_DEBUG) console.error("plan overlay:", err);
            if (statusEl) statusEl.textContent = i18n.error || "";
        }
    }

    /**
     * Plán, který k této trase opravdu patří — nebo nic.
     *
     * Kritéria:
     *   1. výslovné propojení (člověk plán označil jako uskutečněný touto
     *      trasou) — jediné, které nepotřebuje důkaz,
     *   2. jinak musí být plán geometricky sednutý: aspoň AUTO_MIN_PCT %
     *      délky trasy do AUTO_TOL_M metrů od plánu. Shoda dne a blízkost
     *      těžiště jen určují, kdo se ověřuje dřív — samy o sobě nestačí.
     *
     * Když nic nesedí, vrací null a nabídka zůstane prázdná. Většina tras
     * se nikdy neplánovala a porovnání s náhodným plánem z okolí je matoucí
     * — hlásilo by pár procent shody u výšlapu, který s plánem nemá nic
     * společného.
     */
    async function pickPlanId() {
        const usable = plans.filter(p => p.has_geometry);
        if (!usable.length) return null;

        const linked = usable.find(p => p.track_id && p.track_id === cfg.trackId);
        if (linked) return linked.id;

        const lonC = trk.reduce((s, p) => s + p[1], 0) / trk.length;
        const trackDate = (cfg.trackDateStart || "").slice(0, 10);

        // Kandidáti: jen ti z okolí, ve stejný den napřed, pak podle vzdálenosti
        const cands = usable
            .filter(p => p.lat !== null && p.lon !== null)
            .map(p => ({
                p,
                sameDay: !!(p.plan_date && trackDate && p.plan_date === trackDate),
                dist: window.GpxGeo.haversine(lat0, lonC, p.lat, p.lon)
            }))
            .filter(c => c.dist <= AUTO_NEAR_M)
            .sort((a, b) => (b.sameDay - a.sameDay) || (a.dist - b.dist))
            .slice(0, AUTO_MAX_CHECKS);

        for (const c of cands) {
            try {
                const pts = (loadedId === c.p.id && planGeom)
                    ? planGeom : await fetchPlanGeom(c.p.id);
                if (!pts) continue;
                usePlanGeom(c.p.id, pts);
                if (coveragePct(AUTO_TOL_M) >= AUTO_MIN_PCT) return c.p.id;
            } catch (err) {
                if (window.GPX_DEBUG) console.error("plan match:", err);
            }
        }
        planGeom = null; index = null; loadedId = null;
        return null;
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
        // Prázdná první volba: dokud plán nesedí (nebo si ho nevybereš),
        // ať se do mapy nekreslí něco, co s trasou nesouvisí
        const empty = document.createElement("option");
        empty.value = "";
        empty.textContent = i18n.pickNone || "— vyber plán —";
        sel.appendChild(empty);

        plans.forEach(p => {
            const o = document.createElement("option");
            o.value = p.id;
            o.textContent = p.name + (p.plan_date ? " (" + p.plan_date + ")" : "");
            sel.appendChild(o);
        });
        sel.value = "";
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
                if (linkBtn)  linkBtn.style.display  = "none";
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

            // Nic vybraného → zkusit najít plán, který k trase sedí
            if (!sel.value) {
                if (statusEl) statusEl.textContent = i18n.matching || "…";
                const pick = await pickPlanId();
                sel.value = pick ? String(pick) : "";
                if (!pick && statusEl) statusEl.textContent = i18n.noMatch || "";
            }
            refreshLinkBtn();
            if (sel.value) loadPlanGeometry(parseInt(sel.value, 10));
        });
    }

    /* ============ Propojení plánu s trasou ============ */
    /** Popisek tlačítka podle toho, jak je vybraný plán propojený. */
    function refreshLinkBtn() {
        if (!linkBtn || !cfg.isAdmin) return;
        const p = plans.find(x => String(x.id) === String(sel.value));
        if (!p) { linkBtn.style.display = "none"; return; }

        const mine = p.track_id && p.track_id === cfg.trackId;
        linkBtn.style.display = "";
        linkBtn.classList.toggle("plan-btn-active", !!mine);
        linkBtn.textContent = mine
            ? "✔ " + (i18n.linkRemove || "Zrušit propojení")
            : "✔ " + (i18n.linkAdd    || "Označit plán jako uskutečněný");
        // Plán patřící jiné trase jde přepnout, ale ať je to vidět dopředu
        linkBtn.title = (!mine && p.track_id) ? (i18n.linkOther || "") : "";
    }

    async function toggleLink() {
        const p = plans.find(x => String(x.id) === String(sel.value));
        if (!p) return;
        const mine = p.track_id && p.track_id === cfg.trackId;

        const fd = new FormData();
        fd.append("_csrf_token", cfg.csrfToken || "");
        fd.append("plan_id",  p.id);
        fd.append("track_id", mine ? 0 : cfg.trackId);
        linkBtn.disabled = true;
        try {
            const res = await fetch("api/planner/link.php", { method: "POST", body: fd });
            const d   = await res.json();
            if (!d.ok) throw new Error(d.error || "link failed");
            p.track_id = d.track_id;
            refreshLinkBtn();
            if (statusEl) {
                statusEl.textContent = mine ? (i18n.unlinked || "") : (i18n.linked || "");
            }
        } catch (err) {
            if (window.GPX_DEBUG) console.error("plan link:", err);
            if (statusEl) statusEl.textContent = i18n.error || "";
        } finally {
            linkBtn.disabled = false;
        }
    }

    if (linkBtn) linkBtn.addEventListener("click", toggleLink);

    if (sel) sel.addEventListener("change", () => {
        refreshLinkBtn();
        if (!sel.value) {                       // zpět na „— vyber plán —"
            clearLayers();
            planGeom = null; index = null; loadedId = null;
            if (legendEl) legendEl.style.display = "none";
            if (statusEl) statusEl.textContent = "";
            return;
        }
        loadPlanGeometry(parseInt(sel.value, 10));
    });
    if (tol) tol.addEventListener("change", () => { if (planOn && planGeom) analyse(); });

    // Panel se ukáže jen když vůbec existuje nějaký plán s geometrií
    (async () => {
        const has = await loadPlanList();
        if (!has) return;
        wrap.style.display = "";

        let remembered = "0";
        try { remembered = localStorage.getItem("gpx_plan_overlay") || "0"; } catch (e) {}
        if (remembered !== "1" || !btn) return;

        // Volba se pamatuje napříč trasami, ale sama se zapne jen když k této
        // trase nějaký plán opravdu sedí. Většina výšlapů se neplánovala —
        // u nich se panel nechá zavřený a nikomu nic neskáče do mapy.
        const pick = await pickPlanId();
        if (!pick) return;
        sel.value = String(pick);
        btn.click();
    })();
});
