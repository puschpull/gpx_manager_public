/**
 * ===========================================================
 *  GPX Manager – Plánovač výšlapu
 *  Klikáním do mapy waypointy → routing po cestách (Mapy.com,
 *  server-side api/planner/route.php) → statistiky, výškový
 *  profil (api/planner/elevation.php — Mapy.com se zálohou na
 *  Open-Meteo) a export GPX pro Garmin.
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
    /* Aktuální srážky z radaru ČHMÚ — jen v Plánovači a jen když je funkce
       zapnutá v Administraci. Jinde by neměla smysl: u staré trasy dnešní déšť
       nic neříká a v detailu by se navíc bila s radarem v přehrávači. */
    const radarNow = (cfg.radarNow && window.GpxMapFactory.createRadarNowOverlay)
        ? window.GpxMapFactory.createRadarNowOverlay(map, cfg.radarNowI18n || {})
        : null;

    const overlayLayers = Object.assign({}, _overlayBase,
        overlayMapillary ? { "📷 Fotografie (Mapillary)": overlayMapillary } : {},
        radarNow ? { ["🌧️ " + ((cfg.radarNowI18n || {}).layer || "Aktuální srážky (ČHMÚ)")]: radarNow } : {});
    L.control.layers(baseLayers, overlayLayers, { collapsed: true }).addTo(map);

    // Vrstva se zapíná až po vytvoření ovladače, aby se zaškrtávátko srovnalo samo
    if (radarNow) {
        try {
            if (localStorage.getItem("gpx_radar_now") === "1") radarNow.addTo(map);
        } catch (e) { /* privátní režim prohlížeče */ }
        map.on("overlayadd overlayremove", e => {
            if (e.layer !== radarNow) return;
            try { localStorage.setItem("gpx_radar_now", e.type === "overlayadd" ? "1" : "0"); } catch (err) {}
        });
    }
    if (window.GpxMapFactory.createLocateControl) window.GpxMapFactory.createLocateControl(map);

    const savedLayer = localStorage.getItem("gpx_map_layer");
    if (savedLayer && baseLayers[savedLayer]) {
        map.removeLayer(baseOSM);
        baseLayers[savedLayer].addTo(map);
    }
    map.on("baselayerchange", e => localStorage.setItem("gpx_map_layer", e.name));

    // ===== Stav =====
    let waypoints  = [];        // [{lat, lng, marker, manual}] — manual = segment DO tohoto bodu je rovná čára
    let routeLayers = [];       // pole L.polyline (auto úseky modré, ruční oranžové čárkované)
    let routeData  = null;      // poslední výsledek (coords/length/duration + ascent/descent po elevaci)
    let elevChart  = null;      // Chart.js instance
    let computeSeq = 0;         // ochrana proti závodům odpovědí
    let debounceT  = null;
    let currentPlanId = 0;      // 0 = neuložený plán
    let restoring  = false;     // načítání uloženého plánu → nepřepočítávat routing
    let manualMode = false;     // ruční režim: nové body se spojují rovnou čarou (přechod pole/lesa)
    let elevSample = [];        // [[lat,lng],…] — body odpovídající sloupcům výškového profilu
    let hoverMarker = null;     // společný ukazatel mapa ↔ profil
    let radarViewMode = false;  // zapnutá vrstva srážek → plánovač jen prohlíží radar

    const statusEl   = document.getElementById("plan-status");
    const statsEl    = document.getElementById("plan-stats");
    const elevWrap   = document.getElementById("plan-elev-wrap");
    const profileSel = document.getElementById("planProfile");
    const nameInput  = document.getElementById("planName");
    const btnManual  = document.getElementById("planManual");
    const btnReverse = document.getElementById("planReverse");
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

    /**
     * Poznámky k vypočítané trase: omezení u zadaných bodů a jak daleko se
     * kliknutí přichytilo k cestě. Dřív se trasa jen tiše protáhla přes
     * zavřený úsek nebo se bod odsunul o stovky metrů, aniž by to bylo znát.
     */
    function showRouteNotes(restrictions, maxSnapM) {
        const DRUHY = {
            NO_ENTRY:          i18n.restrNoEntry    || "zákaz vstupu",
            RESTRICTED_ENTRY:  i18n.restrLimited    || "omezený vstup (povolenka, poplatek)",
            PEDESTRIAN_ZONE:   i18n.restrPedestrian || "pěší zóna",
            CLOSURE:           i18n.restrClosure    || "dočasná uzavírka",
            OTHER_RESTRICTION: i18n.restrOther      || "jiné omezení"
        };
        const casti = [];

        if (restrictions && restrictions.length) {
            const popis = restrictions.map(r =>
                `${(i18n.pointWord || "bod")} ${r.index + 1} — ${DRUHY[r.type] || DRUHY.OTHER_RESTRICTION}`);
            casti.push("⚠ " + (i18n.restrHead || "Omezení na trase") + ": " + popis.join(", "));
        }
        // Pod 50 m je přichycení běžné (klikneš vedle pěšiny) a nemá cenu o něm psát.
        if (maxSnapM >= 50) {
            casti.push("↔ " + (i18n.snapNote || "Nejvzdálenější bod se posunul na nejbližší cestu o")
                     + " " + Math.round(maxSnapM) + " m");
        }

        if (casti.length) {
            setStatus(casti.join("   ·   "), "error");
        } else {
            setStatus(i18n.done || "Hotovo.", "done");
        }
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
        if (btnReverse) btnReverse.disabled = waypoints.length < 2;
        btnClear.disabled  = waypoints.length === 0;
        btnExport.disabled = !hasRoute;
        if (btnSave)   btnSave.disabled   = !hasRoute;          // návštěvník tlačítko nemá
        if (btnDelete) btnDelete.disabled = !(planListEl && planListEl.value);
    }

    // ===== Waypoint markery (číslované, tažitelné, klik = smazat) =====
    //  start = zelený, cíl = červený, ruční bod = oranžový, ostatní = modrý
    function wptIcon(n, isLast, isManual) {
        let bg = "#1565c0";
        if (n === 1) bg = "#2e7d32";
        else if (isLast) bg = "#c62828";
        else if (isManual) bg = "#ef6c00";
        return L.divIcon({
            className: "",
            html: `<div class="plan-wpt" style="background:${bg}">${n}</div>`,
            iconSize: [26, 26], iconAnchor: [13, 13]
        });
    }

    function refreshMarkers() {
        waypoints.forEach((w, i) => {
            w.marker.setIcon(wptIcon(i + 1, i === waypoints.length - 1, !!w.manual));
        });
        const el = document.getElementById("planWpts");
        if (el) el.textContent = waypoints.length;
    }

    function addWaypoint(latlng, skipCompute, manualFlag) {
        // manual = segment vedoucí DO tohoto bodu je rovná čára (u 1. bodu bez významu)
        const isManual = (manualFlag !== undefined) ? !!manualFlag : manualMode;
        const w = { lat: latlng.lat, lng: latlng.lng, marker: null, manual: isManual };
        const m = L.marker([latlng.lat, latlng.lng], {
            icon: wptIcon(waypoints.length + 1, true, isManual),
            draggable: true
        }).addTo(map);
        m.on("dragend", () => {
            const p = m.getLatLng();
            w.lat = p.lat; w.lng = p.lng;
            scheduleCompute();
        });
        m.on("click", () => { if (!radarViewMode) removeWaypoint(w); });
        if (radarViewMode && m.dragging) m.dragging.disable();   // plán načtený při zapnutém radaru
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

    function clearRouteLayers() {
        routeLayers.forEach(l => map.removeLayer(l));
        routeLayers = [];
    }

    function clearRoute() {
        clearRouteLayers();
        routeData = null;
        statsEl.style.display = "none";
        elevWrap.style.display = "none";
        if (elevChart) { elevChart.destroy(); elevChart = null; }
        // Bez tohohle by ukazatel dál skákal po bodech zrušené trasy.
        elevSample = [];
        hideHover();
    }

    // ===== Ruční režim (rovné úseky přes pole/les) =====
    function setManualMode(on) {
        manualMode = on;
        if (btnManual) {
            btnManual.classList.toggle("plan-btn-active", on);
            btnManual.setAttribute("aria-pressed", on ? "true" : "false");
        }
        if (on) {
            setStatus(i18n.manualOn || "Ruční režim.", "");
        } else if (routeData) {
            setStatus(i18n.done || "", "done");
        } else {
            setStatus(waypoints.length === 1 ? (i18n.onePoint || "") : (i18n.clickHint || ""), "");
        }
    }
    if (btnManual) btnManual.addEventListener("click", () => setManualMode(!manualMode));
    if (btnReverse) btnReverse.addEventListener("click", reverseRoute);

    // Nominální rychlost (m/s) pro odhad času ručních úseků dle profilu
    function nominalSpeedMs(profile) {
        if (profile.indexOf("car") === 0)  return 50000 / 3600;
        if (profile.indexOf("bike") === 0) return 15000 / 3600;
        return 4500 / 3600; // pěšky
    }

    // Rovný úsek A→B rozdělený na body ~po 80 m (hladší výškový profil i GPX).
    // Vrací body VČETNĚ B, BEZ A.
    function densify(A, B, stepM) {
        const d = window.GpxGeo.haversine(A[0], A[1], B[0], B[1]);
        const n = Math.max(1, Math.round(d / stepM));
        const out = [];
        for (let k = 1; k <= n; k++) {
            const f = k / n;
            out.push([A[0] + (B[0] - A[0]) * f, A[1] + (B[1] - A[1]) * f]);
        }
        return out;
    }

    // Rozdělí waypointy na úseky: souvislé auto body → jeden routing požadavek,
    // ruční bod → samostatný rovný úsek.
    /**
     * Otočí směr trasy — prohodí start a cíl.
     *
     * Pozor na ruční úseky: příznak `manual` sedí na CÍLOVÉM bodu segmentu
     * (`manual` u bodu i znamená, že úsek z i-1 do i je rovná čára). Po otočení
     * se tentýž fyzický úsek přiřazuje k opačnému konci, takže nestačí pole
     * obrátit — příznaky se musí posunout o jednu pozici. Bez toho by se ruční
     * úseky rozjely a trasa by se přepočítala jinudy.
     */
    function reverseRoute() {
        if (waypoints.length < 2) return;

        const n     = waypoints.length;
        const flags = waypoints.map(w => !!w.manual);   // flags[i] popisuje úsek (i-1)→i
        const rev   = waypoints.slice().reverse();

        for (let j = 0; j < n; j++) {
            const oldIdx = n - 1 - j;                   // index téhož bodu v původním pořadí
            // Úsek vedoucí DO rev[j] je původní úsek oldIdx→oldIdx+1, jehož
            // příznak byl na oldIdx+1. Nový start (j = 0) žádný příchozí nemá.
            rev[j].manual = (oldIdx + 1 < n) ? flags[oldIdx + 1] : false;
        }

        waypoints = rev;
        refreshMarkers();          // přečísluje popisky, přebarví start/cíl a ruční body
        updateButtons();
        // Trasa se musí přepočítat — v opačném směru nemusí vést stejně
        // (jednosměrky, zákazy) a mění se i profil stoupání.
        scheduleCompute();
    }

    function buildParts() {
        const parts = [];
        let i = 1;
        while (i < waypoints.length) {
            if (waypoints[i].manual) {
                parts.push({ type: "manual", a: waypoints[i - 1], b: waypoints[i] });
                i++;
            } else {
                const run = [waypoints[i - 1], waypoints[i]];
                i++;
                while (i < waypoints.length && !waypoints[i].manual) { run.push(waypoints[i]); i++; }
                parts.push({ type: "auto", pts: run });
            }
        }
        return parts;
    }

    // ===== Výpočet trasy (debounce — šetří Mapy.com kvótu při rychlém klikání) =====
    function scheduleCompute() {
        if (restoring) return;   // při načítání uloženého plánu se routing nevolá
        updateButtons();
        markElevStale();
        clearTimeout(debounceT);
        debounceT = setTimeout(compute, 600);
    }

    /**
     * Zešedí výškový profil, dokud se nepřepočítá.
     *
     * Značky na mapě se překreslí okamžitě, ale nový profil je hotový až po
     * debouncingu, routingu a dotazu na výšky — u delšího plánu to bylo
     * naměřeno na 6 sekund. Do té doby graf ukazoval STARÝ průběh k už otočené
     * trase, což vypadalo, jako by se vyráželo od cíle. Zešednutí říká
     * „tohle ještě neplatí“ dřív, než uživatel stihne špatný závěr.
     */
    function markElevStale() {
        if (elevWrap && elevChart) elevWrap.classList.add("plan-elev-stale");
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

        const profile = profileSel.value || "foot_hiking";
        const parts = buildParts();

        try {
            // Auto úseky routujeme paralelně (Mapy.com); ruční jsou rovné čáry bez API.
            const results = await Promise.all(parts.map(part => {
                if (part.type !== "auto") return Promise.resolve(null);
                const pts = part.pts.map(w => `${w.lat.toFixed(6)},${w.lng.toFixed(6)}`).join(";");
                return fetch(`api/planner/route.php?points=${encodeURIComponent(pts)}&profile=${encodeURIComponent(profile)}`)
                    .then(r => r.json());
            }));
            if (seq !== computeSeq) return;   // mezitím přišel novější požadavek

            // Poskládat úseky v pořadí do jedné geometrie + spočítat délku/čas
            const fullCoords = [];
            const rendered   = [];   // {coords, manual} — pro vykreslení
            const nomSpeed   = nominalSpeedMs(profile);
            let totalLen = 0, totalDur = 0;
            // Mapy.com hlásí, jestli bod leží v omezené oblasti a jak daleko
            // se přichytil k cestě. Sbírá se napříč úseky, vypíše se najednou.
            const allRestrictions = [];
            let maxSnap = 0;

            const pushCoord = c => {
                const last = fullCoords[fullCoords.length - 1];
                if (!last || last[0] !== c[0] || last[1] !== c[1]) fullCoords.push(c);
            };

            for (let pi = 0; pi < parts.length; pi++) {
                const part = parts[pi];
                if (part.type === "manual") {
                    const A = [part.a.lat, part.a.lng], B = [part.b.lat, part.b.lng];
                    if (fullCoords.length === 0) pushCoord(A);
                    densify(A, B, 80).forEach(pushCoord);
                    const d = window.GpxGeo.haversine(A[0], A[1], B[0], B[1]);
                    totalLen += d;
                    totalDur += nomSpeed > 0 ? d / nomSpeed : 0;
                    rendered.push({ coords: [A, B], manual: true });
                } else {
                    const r = results[pi];
                    if (!r || !r.ok || !Array.isArray(r.coords) || r.coords.length < 2) {
                        clearRoute();
                        updateButtons();
                        setStatus((i18n.error || "Chyba") + ": " + ((r && r.error) || "?"), "error");
                        return;
                    }
                    r.coords.forEach(pushCoord);
                    totalLen += r.length_m   || 0;
                    totalDur += r.duration_s || 0;
                    rendered.push({ coords: r.coords, manual: false });
                    if (Array.isArray(r.restrictions)) allRestrictions.push(...r.restrictions);
                    if (typeof r.max_snap_m === "number") maxSnap = Math.max(maxSnap, r.max_snap_m);
                }
            }

            if (fullCoords.length < 2) {
                clearRoute();
                updateButtons();
                setStatus((i18n.error || "Chyba") + ".", "error");
                return;
            }

            routeData = { ok: true, coords: fullCoords, length_m: totalLen, duration_s: totalDur };

            // Vykreslit: auto úseky plnou modrou, ruční oranžovou čárkovanou
            clearRouteLayers();
            rendered.forEach(seg => {
                const line = seg.manual
                    ? L.polyline(seg.coords, { color: "#ef6c00", weight: 3, opacity: 0.9, dashArray: "6 8" })
                    : L.polyline(seg.coords, { color: "#1565c0", weight: 4, opacity: 0.85 });
                line.addTo(map);
                routeLayers.push(line);
            });

            document.getElementById("planDist").textContent = fmtKm(totalLen);
            document.getElementById("planDur").textContent  = "~" + fmtDur(totalDur);
            document.getElementById("planAsc").textContent  = "…";
            document.getElementById("planDesc").textContent = "…";
            statsEl.style.display = "flex";
            showRouteNotes(allRestrictions, maxSnap);
            updateButtons();

            loadElevation(fullCoords, seq);
            loadWeather();
        } catch (err) {
            if (seq !== computeSeq) return;
            if (window.GPX_DEBUG) console.error("planner:", err);
            setStatus((i18n.error || "Chyba") + ".", "error");
        }
    }

    profileSel.addEventListener("change", () => { if (waypoints.length >= 2) scheduleCompute(); });

    /* ===== Provázání mapy a výškového profilu =====
       Ukazatel je jeden a společný: ať jedeš myší po trase v mapě, nebo po
       grafu, druhá strana se dorovná. Vzor je převzatý z detailu trasy
       (graf → mapa, `moveHoverMarker`) a z přehrávače (mapa → graf,
       `setActiveElements`), tady jsou obě strany pohromadě.

       Ukazatel je `interactive: false` — jinak by kroužek bral kliknutí
       a v plánovači by se pod ním nedal přidat bod. */

    function ensureHoverMarker() {
        if (!hoverMarker) {
            hoverMarker = L.circleMarker([0, 0], {
                radius: 6, color: "#000", weight: 2,
                fillColor: "#fff", fillOpacity: 1,
                interactive: false
            }).addTo(map);
        }
    }

    function showHoverAt(idx) {
        if (idx == null || !elevSample[idx]) return;
        ensureHoverMarker();
        hoverMarker.setLatLng(elevSample[idx]);
    }

    function hideHover() {
        if (hoverMarker) { map.removeLayer(hoverMarker); hoverMarker = null; }
    }

    /** Zvýrazní bod v grafu (a jeho bublinu) bez překreslení celého grafu. */
    function highlightChart(idx) {
        if (!elevChart || idx == null) return;
        try {
            elevChart.setActiveElements([{ datasetIndex: 0, index: idx }]);
            elevChart.tooltip.setActiveElements([{ datasetIndex: 0, index: idx }], { x: 0, y: 0 });
            elevChart.update("none");
        } catch (e) {
            if (window.GPX_DEBUG) console.warn("planner chart sync:", e);
        }
    }

    function clearChartHighlight() {
        if (!elevChart) return;
        try {
            elevChart.setActiveElements([]);
            elevChart.tooltip.setActiveElements([], { x: 0, y: 0 });
            elevChart.update("none");
        } catch (e) { /* graf mezitím zanikl */ }
    }

    // ---- Mapa → profil ----
    // Hledá se nejbližší bod trasy k myši. Bodů je max 200, takže lineární
    // průchod stačí; drží se ale na jednom vyhodnocení za snímek, aby se
    // při rychlém pohybu myší nepočítalo zbytečně.
    let hoverRaf = null;
    map.on("mousemove", e => {
        if (!elevSample.length || hoverRaf) return;
        hoverRaf = requestAnimationFrame(() => {
            hoverRaf = null;
            const mouse = map.latLngToContainerPoint(e.latlng);
            let best = -1, bestD = Infinity;
            for (let i = 0; i < elevSample.length; i++) {
                const p = map.latLngToContainerPoint(elevSample[i]);
                const d = (p.x - mouse.x) ** 2 + (p.y - mouse.y) ** 2;
                if (d < bestD) { bestD = d; best = i; }
            }
            // Práh 40 px: mimo trasu ukazatel nemá co ukazovat.
            if (best >= 0 && bestD <= 40 * 40) {
                showHoverAt(best);
                highlightChart(best);
            } else {
                hideHover();
                clearChartHighlight();
            }
        });
    });

    map.on("mouseout", () => { hideHover(); clearChartHighlight(); });

    // ---- Profil → mapa ----
    // Posluchače visí na plátně, ne na instanci grafu — plátno v DOM zůstává,
    // kdežto graf se při každém přepočtu ruší a tvoří znovu.
    const elevCanvas = document.getElementById("planElevChart");
    if (elevCanvas) {
        elevCanvas.addEventListener("mousemove", evt => {
            if (!elevChart || !elevSample.length) return;
            const el = elevChart.getElementsAtEventForMode(evt, "nearest", { intersect: false }, false);
            if (el && el.length) showHoverAt(el[0].index);
        });
        elevCanvas.addEventListener("mouseleave", hideHover);
    }

    // ===== Výškový profil =====
    // Výšky bere api/planner/elevation.php — primárně z Mapy.com, při potížích
    // spadne na Open-Meteo. Pro české hory je jejich model měřitelně přesnější
    // (podrobnosti v hlavičce toho souboru), a zvládne 256 bodů místo stovky.
    async function loadElevation(coords, seq) {
        // Podvzorkovat na max 200 bodů, spočítat kumulativní vzdálenost.
        // Dvě stě je kompromis: Mapy.com by vzaly 256, ale záloha na Open-Meteo
        // by pak potřebovala tři požadavky místo dvou.
        const maxPts = 200;
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
            const points = sample.map(c => `${c[0].toFixed(5)},${c[1].toFixed(5)}`).join(";");
            const res  = await fetch(`api/planner/elevation.php?points=${encodeURIComponent(points)}`);
            const data = await res.json();
            if (seq !== computeSeq) return;
            const elev = data && data.ok && data.elevation;
            if (!Array.isArray(elev) || elev.length !== sample.length) return;
            if (window.GPX_DEBUG) console.log("planner elevation source:", data.source);

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

            // Souřadnice bodů grafu si podržíme — bez nich by nešlo provázat
            // profil s mapou (index v grafu ↔ místo na trase).
            elevSample = sample;

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
        if (elevWrap) elevWrap.classList.remove("plan-elev-stale");   // data jsou aktuální

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
        fd.append("waypoints", JSON.stringify(waypoints.map(w => [+w.lat.toFixed(6), +w.lng.toFixed(6), w.manual ? 1 : 0])));
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
            (p.waypoints || []).forEach(c => addWaypoint({ lat: c[0], lng: c[1] }, true, c[2] ? 1 : 0));
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
                clearRouteLayers();
                routeLayers.push(L.polyline(p.geometry, { color: "#1565c0", weight: 4, opacity: 0.85 }).addTo(map));
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
            showPlanTrack(p);
            setStatus(i18n.loaded || "Načteno.", "done");
        } catch (err) {
            setStatus((i18n.error || "Chyba") + ".", "error");
        }
    }

    /**
     * Plán, který už byl prošlapán, ukáže odkaz na skutečnou trasu.
     * Propojení se zakládá v detailu trasy (tlačítko u porovnání s plánem).
     */
    function showPlanTrack(p) {
        const wrap = document.getElementById("planTrackWrap");
        const link = document.getElementById("planTrackLink");
        if (!wrap || !link) return;
        if (!p || !p.track_id) { wrap.style.display = "none"; return; }

        const datum = p.track_date ? " · " + String(p.track_date).slice(0, 10) : "";
        link.textContent = (i18n.doneTrack || "Uskutečněno") + ": "
                         + (p.track_name || "#" + p.track_id) + datum;
        link.href = "detail.php?id=" + encodeURIComponent(p.track_id);
        wrap.style.display = "";
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

    if (btnSave)   btnSave.addEventListener("click", savePlan);
    if (btnDelete) btnDelete.addEventListener("click", deletePlan);
    if (planListEl) {
        planListEl.addEventListener("change", () => {
            updateButtons();
            if (planListEl.value) loadPlan(planListEl.value);
        });
        refreshPlansList();
    }

    // ===== Export / Import všech plánů (soubor .json) — admin =====
    const btnDbExport  = document.getElementById("planDbExport");
    const btnDbImport  = document.getElementById("planDbImport");
    const dbImportFile = document.getElementById("planDbImportFile");
    const dbModeSel    = document.getElementById("planDbMode");

    async function exportPlans() {
        try {
            const res  = await fetch("api/planner/export.php");
            const data = await res.json();
            if (!data.ok || !data.export || !data.export.plans.length) {
                setStatus(i18n.exportNone || "Žádné plány.", "");
                return;
            }
            const blob = new Blob([JSON.stringify(data.export, null, 1)], { type: "application/json" });
            const a = document.createElement("a");
            a.href = URL.createObjectURL(blob);
            a.download = "gpx-manager-plany-" + new Date().toISOString().slice(0, 10) + ".json";
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(() => URL.revokeObjectURL(a.href), 5000);
            setStatus((i18n.exportDone || "Staženo: {n}").replace("{n}", data.export.plans.length), "done");
        } catch (err) {
            if (window.GPX_DEBUG) console.error("planner export:", err);
            setStatus((i18n.error || "Chyba") + ".", "error");
        }
    }

    function importPlans(file) {
        if (!file) return;
        const mode = dbModeSel ? dbModeSel.value : "new";
        if (mode === "replace" && !confirm(i18n.importRepl || "Nahradit všechny plány?")) return;

        const reader = new FileReader();
        reader.onload = async () => {
            const fd = new FormData();
            fd.append("_csrf_token", CSRF);
            fd.append("data", String(reader.result || ""));
            fd.append("mode", mode);
            try {
                const res  = await fetch("api/planner/import.php", { method: "POST", body: fd });
                const data = await res.json();
                if (data.ok) {
                    let msg = (i18n.importDone || "Importováno: {n}").replace("{n}", data.imported);
                    if (data.duplicates > 0) {
                        msg += " (" + (i18n.importDup || "duplicit: {n}").replace("{n}", data.duplicates) + ")";
                    }
                    setStatus(msg, "done");
                    refreshPlansList();
                } else {
                    setStatus((i18n.importBad || "Chyba") + " " + (data.error || ""), "error");
                }
            } catch (err) {
                setStatus(i18n.importBad || "Chyba.", "error");
            }
        };
        reader.onerror = () => setStatus(i18n.importBad || "Chyba.", "error");
        reader.readAsText(file);
    }

    if (btnDbExport) btnDbExport.addEventListener("click", exportPlans);
    if (btnDbImport && dbImportFile) {
        btnDbImport.addEventListener("click", () => dbImportFile.click());
        dbImportFile.addEventListener("change", () => {
            importPlans(dbImportFile.files[0]);
            dbImportFile.value = "";   // ať jde nahrát stejný soubor znovu
        });
    }

    // ===== Klik do mapy = nový waypoint =====
    /* Klik přímo na vykreslenou trasu bod NEPŘIDÁ.
       Když člověk jede myší po čáře a čte výškový profil, snadno se stane,
       že klikne — a plán se rozjede o smyčku navíc na konec. Přidat bod
       „na trase“ přitom nic nepřináší: routing kliknutí stejně přichytí na
       nejbližší cestu, takže stačí kliknout kousek vedle čáry. */
    const CLICK_ROUTE_PX = 12;

    /** Vzdálenost bodu od nakreslené trasy v pixelech (po úsečkách, ne po vrcholech). */
    function pixelsFromRoute(latlng) {
        const c = routeData && routeData.coords;
        if (!c || c.length < 2) return Infinity;
        const p = map.latLngToContainerPoint(latlng);
        let best = Infinity, prev = map.latLngToContainerPoint(c[0]);
        for (let i = 1; i < c.length; i++) {
            const cur = map.latLngToContainerPoint(c[i]);
            const d = L.LineUtil.pointToSegmentDistance(p, prev, cur);
            if (d < best) best = d;
            prev = cur;
        }
        return best;
    }

    /** Dočasná hláška ve stavovém řádku — původní text se po chvíli vrátí
        (jinak by upozornění na omezení na trase zmizelo kvůli jednomu kliknutí). */
    let hintTimer = null;
    function flashStatus(text) {
        if (!statusEl) return;
        const prevText = statusEl.textContent, prevCls = statusEl.className;
        setStatus(text, "");
        clearTimeout(hintTimer);
        hintTimer = setTimeout(() => {
            if (statusEl.textContent === text) {
                statusEl.textContent = prevText;
                statusEl.className   = prevCls;
            }
        }, 4000);
    }

    /* ===== Režim prohlížeče radaru =====
       Zapnutá vrstva „Aktuální srážky" přepne plánovač do čtení: klikáním se
       nepřidávají body, existující se nedají přetáhnout ani smazat kliknutím.
       Když člověk sleduje, kam se déšť táhne, klikne a posouvá mapou často —
       a každý takový klik by jinak zapsal bod do plánu. Tlačítka Zpět,
       Vyčistit i Uložit zůstávají, ta se mačkají vědomě. */
    let statusBeforeRadar = null;

    function radarViewText() {
        return i18n.radarViewOn
            || "Vrstva srážek je zapnutá — plánovač je teď jen prohlížeč radaru. Body se nepřidávají ani nepřesouvají.";
    }

    function setRadarViewMode(on) {
        if (radarViewMode === on) return;
        radarViewMode = on;
        waypoints.forEach(w => {
            if (!w.marker || !w.marker.dragging) return;
            if (on) w.marker.dragging.disable(); else w.marker.dragging.enable();
        });
        map.getContainer().classList.toggle("plan-radar-view", on);

        if (on) {
            statusBeforeRadar = statusEl ? { text: statusEl.textContent, cls: statusEl.className } : null;
            setStatus(radarViewText(), "");
        } else {
            // původní hlášku vracíme jen když ji mezitím nic nepřepsalo
            if (statusEl && statusEl.textContent === radarViewText()) {
                if (statusBeforeRadar) {
                    statusEl.textContent = statusBeforeRadar.text;
                    statusEl.className   = statusBeforeRadar.cls;
                } else {
                    setStatus(i18n.clickHint || "", "");
                }
            }
            statusBeforeRadar = null;
        }
    }

    if (radarNow) {
        map.on("overlayadd overlayremove", e => {
            if (e.layer === radarNow) setRadarViewMode(e.type === "overlayadd");
        });
        if (map.hasLayer(radarNow)) setRadarViewMode(true);   // vrstva zapamatovaná z minule
    }

    map.on("click", e => {
        if (radarViewMode) {
            flashStatus(i18n.radarViewBlocked
                || "Body se teď nepřidávají — je zapnutá vrstva Aktuální srážky.");
            return;
        }
        if (pixelsFromRoute(e.latlng) <= CLICK_ROUTE_PX) {
            flashStatus(i18n.clickOnRoute
                || "Klik na trasu bod nepřidal. Nový bod přidej kousek vedle čáry.");
            return;
        }
        addWaypoint(e.latlng);
    });

    // ===== Zpět / Vyčistit =====
    btnUndo.addEventListener("click", () => {
        const w = waypoints[waypoints.length - 1];
        if (w) removeWaypoint(w);
    });

    btnClear.addEventListener("click", () => {
        showPlanTrack(null);
        waypoints.forEach(w => map.removeLayer(w.marker));
        waypoints = [];
        currentPlanId = 0;
        if (planListEl) planListEl.value = "";
        if (weatherEl) weatherEl.style.display = "none";
        if (manualMode) setManualMode(false);
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
