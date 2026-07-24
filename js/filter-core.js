/**
 * ===========================================================
 *  GPX Track Cleaner – Core Filtering Engine
 *  Ciste filtrovaci algoritmy bez DOM zavislosti
 * ===========================================================
 */

if (window.GPX_DEBUG) console.log("filter-core.js nacten");

window.GpxFilter = (function () {
    "use strict";

    /* ===== Pomocne funkce ===== */

    // Delegat na sdileny GpxGeo — viz js/lib/geo-utils.js
    function toRad(d) { return window.GpxGeo.toRad(d); }
    function haversine(lat1, lon1, lat2, lon2) { return window.GpxGeo.haversine(lat1, lon1, lat2, lon2); }

    /**
     * Kolma vzdalenost bodu od usecky (pro Douglas-Peucker)
     * Vsechno v metrech pres haversine
     */
    function crossTrackDistance(point, lineStart, lineEnd) {
        const d13 = haversine(lineStart.lat, lineStart.lon, point.lat, point.lon);
        const d12 = haversine(lineStart.lat, lineStart.lon, lineEnd.lat, lineEnd.lon);
        if (d12 === 0) return d13;

        const brng13 = bearing(lineStart.lat, lineStart.lon, point.lat, point.lon);
        const brng12 = bearing(lineStart.lat, lineStart.lon, lineEnd.lat, lineEnd.lon);
        return Math.abs(Math.asin(Math.sin(d13 / 6371000) * Math.sin(toRad(brng13 - brng12))) * 6371000);
    }

    function bearing(lat1, lon1, lat2, lon2) {
        const dLon = toRad(lon2 - lon1);
        const y = Math.sin(dLon) * Math.cos(toRad(lat2));
        const x = Math.cos(toRad(lat1)) * Math.sin(toRad(lat2)) -
                  Math.sin(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.cos(dLon);
        return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
    }

    /* ===== GPX Parser ===== */

    function parseGpx(xmlText) {
        const parser = new DOMParser();
        const xml = parser.parseFromString(xmlText, "application/xml");
        const segments = xml.getElementsByTagName("trkseg");
        const points = [];
        let globalIdx = 0;

        for (let s = 0; s < segments.length; s++) {
            if (s > 0 && points.length > 0 && points[points.length - 1] !== null) {
                points.push(null); // segment boundary
            }
            const pts = segments[s].getElementsByTagName("trkpt");
            for (let i = 0; i < pts.length; i++) {
                const lat = parseFloat(pts[i].getAttribute("lat"));
                const lon = parseFloat(pts[i].getAttribute("lon"));
                const eleEl = pts[i].getElementsByTagName("ele")[0];
                const timeEl = pts[i].getElementsByTagName("time")[0];
                const ele = eleEl ? parseFloat(eleEl.textContent) : null;
                const time = timeEl ? new Date(timeEl.textContent).getTime() : null;
                points.push({ lat, lon, ele, time, origIndex: globalIdx });
                globalIdx++;
            }
        }

        return points;
    }

    /* ===== Vypocet rychlosti s klouzavym oknem ===== */

    function calcSpeeds(points, windowSize) {
        const speeds = new Array(points.length).fill(0);

        // Nejprve vypocitame point-to-point rychlost pro kazdy bod
        const ptSpeeds = new Array(points.length).fill(0);
        let prevReal = null;
        let prevRealIdx = -1;
        for (let i = 0; i < points.length; i++) {
            if (points[i] === null) { prevReal = null; continue; }
            if (prevReal && prevReal.time !== null && points[i].time !== null) {
                const dt = (points[i].time - prevReal.time) / 1000;
                if (dt > 0) {
                    const dist = haversine(prevReal.lat, prevReal.lon, points[i].lat, points[i].lon);
                    ptSpeeds[i] = (dist / dt) * 3.6; // km/h
                }
            }
            prevReal = points[i];
            prevRealIdx = i;
        }

        // Klouzavy prumer pro vyhladneni
        const half = Math.floor(windowSize / 2);
        // Vytvorime pole indexu realnych bodu
        const realIndices = [];
        for (let i = 0; i < points.length; i++) {
            if (points[i] !== null) realIndices.push(i);
        }

        for (let ri = 0; ri < realIndices.length; ri++) {
            const idx = realIndices[ri];
            let sum = 0;
            let count = 0;
            for (let w = Math.max(0, ri - half); w <= Math.min(realIndices.length - 1, ri + half); w++) {
                sum += ptSpeeds[realIndices[w]];
                count++;
            }
            speeds[idx] = count > 0 ? sum / count : 0;
        }

        return speeds;
    }

    /* ===== Filtr 1: Max rychlost ===== */

    function filterMaxSpeed(points, maxSpeedKmh, windowSize) {
        const hasTime = points.some(p => p !== null && p.time !== null);
        if (!hasTime) return { kept: [...points], removed: [], warning: "no_time" };

        const speeds = calcSpeeds(points, windowSize);
        const kept = [];
        const removed = [];

        for (let i = 0; i < points.length; i++) {
            if (points[i] === null) {
                kept.push(null);
                continue;
            }
            if (speeds[i] > maxSpeedKmh) {
                removed.push(points[i]);
            } else {
                kept.push(points[i]);
            }
        }

        return { kept, removed };
    }

    /* ===== Filtr 2: GPS spike detekce ===== */

    function filterSpikes(points, distanceM, timeS) {
        const real = [];
        const realIdx = [];
        for (let i = 0; i < points.length; i++) {
            if (points[i] !== null) {
                real.push(points[i]);
                realIdx.push(i);
            }
        }

        const spikeSet = new Set();

        for (let r = 1; r < real.length - 1; r++) {
            const prev = real[r - 1];
            const curr = real[r];
            const next = real[r + 1];

            const dPrev = haversine(prev.lat, prev.lon, curr.lat, curr.lon);
            const dNext = haversine(curr.lat, curr.lon, next.lat, next.lon);
            const dPN = haversine(prev.lat, prev.lon, next.lat, next.lon);

            if (dPrev > distanceM && dNext > distanceM && dPN < distanceM) {
                // Casova podminka
                if (prev.time !== null && next.time !== null) {
                    const dt = Math.abs(next.time - prev.time) / 1000;
                    if (dt < timeS) {
                        spikeSet.add(realIdx[r]);
                    }
                } else {
                    // Bez casu — pouzijeme jen vzdalenostni heuristiku
                    spikeSet.add(realIdx[r]);
                }
            }
        }

        const kept = [];
        const removed = [];
        for (let i = 0; i < points.length; i++) {
            if (points[i] === null) {
                kept.push(null);
            } else if (spikeSet.has(i)) {
                removed.push(points[i]);
            } else {
                kept.push(points[i]);
            }
        }

        return { kept, removed };
    }

    /* ===== Filtr 3: Stani na miste ===== */

    function filterStationary(points, radiusM, minTimeS) {
        const kept = [];
        const removed = [];

        // Pracujeme po segmentech (oddelene null)
        let segment = [];
        const segments = [];
        for (let i = 0; i < points.length; i++) {
            if (points[i] === null) {
                if (segment.length > 0) segments.push(segment);
                segment = [];
            } else {
                segment.push({ point: points[i], index: i });
            }
        }
        if (segment.length > 0) segments.push(segment);

        const removeSet = new Set();

        for (const seg of segments) {
            let clusterStart = 0;
            while (clusterStart < seg.length) {
                let clusterEnd = clusterStart;
                const anchor = seg[clusterStart].point;

                // Najdi konec klastru
                while (clusterEnd + 1 < seg.length) {
                    const nextP = seg[clusterEnd + 1].point;
                    if (haversine(anchor.lat, anchor.lon, nextP.lat, nextP.lon) <= radiusM) {
                        clusterEnd++;
                    } else {
                        break;
                    }
                }

                // Zkontroluj cas
                const first = seg[clusterStart].point;
                const last = seg[clusterEnd].point;
                const hasTime = first.time !== null && last.time !== null;
                const dt = hasTime ? (last.time - first.time) / 1000 : 0;
                const clusterSize = clusterEnd - clusterStart + 1;

                if (clusterSize > 2 && (!hasTime || dt >= minTimeS)) {
                    // Oznac prostredni body k odstraneni
                    for (let j = clusterStart + 1; j < clusterEnd; j++) {
                        removeSet.add(seg[j].index);
                    }
                }

                clusterStart = clusterEnd + 1;
            }
        }

        for (let i = 0; i < points.length; i++) {
            if (points[i] === null) {
                kept.push(null);
            } else if (removeSet.has(i)) {
                removed.push(points[i]);
            } else {
                kept.push(points[i]);
            }
        }

        return { kept, removed };
    }

    /* ===== Filtr 4: Vyskove anomalie ===== */

    function filterElevationSpikes(points, maxEleChangeM) {
        const real = [];
        const realIdx = [];
        for (let i = 0; i < points.length; i++) {
            if (points[i] !== null && points[i].ele !== null) {
                real.push(points[i]);
                realIdx.push(i);
            }
        }

        const spikeSet = new Set();

        for (let r = 1; r < real.length - 1; r++) {
            const prev = real[r - 1];
            const curr = real[r];
            const next = real[r + 1];

            const diffPrev = Math.abs(curr.ele - prev.ele);
            const diffNext = Math.abs(curr.ele - next.ele);
            const diffPN = Math.abs(next.ele - prev.ele);

            // Bod vycniva vuci obema sousedum, ale sousede si jsou blizko
            if (diffPrev > maxEleChangeM && diffNext > maxEleChangeM && diffPN < maxEleChangeM) {
                spikeSet.add(realIdx[r]);
            }
        }

        const kept = [];
        const removed = [];
        for (let i = 0; i < points.length; i++) {
            if (points[i] === null) {
                kept.push(null);
            } else if (spikeSet.has(i)) {
                removed.push(points[i]);
            } else {
                kept.push(points[i]);
            }
        }

        return { kept, removed };
    }

    /* ===== Filtr 5: Kratke segmenty ===== */

    function filterShortSegments(points, minDistM, minTimeS) {
        // Po predchozich filtrech mohou vzniknout mezery — detekujeme segmenty
        // Segment = souvisla sekvence non-null bodu
        const segments = [];
        let current = [];
        let currentIndices = [];

        for (let i = 0; i < points.length; i++) {
            if (points[i] === null) {
                if (current.length > 0) {
                    segments.push({ points: current, indices: currentIndices });
                    current = [];
                    currentIndices = [];
                }
            } else {
                current.push(points[i]);
                currentIndices.push(i);
            }
        }
        if (current.length > 0) {
            segments.push({ points: current, indices: currentIndices });
        }

        const removeSet = new Set();

        for (const seg of segments) {
            if (seg.points.length < 2) {
                seg.indices.forEach(i => removeSet.add(i));
                continue;
            }

            // Celkova vzdalenost segmentu
            let dist = 0;
            for (let j = 1; j < seg.points.length; j++) {
                dist += haversine(
                    seg.points[j - 1].lat, seg.points[j - 1].lon,
                    seg.points[j].lat, seg.points[j].lon
                );
            }

            // Celkovy cas segmentu
            const first = seg.points[0];
            const last = seg.points[seg.points.length - 1];
            const hasTime = first.time !== null && last.time !== null;
            const dt = hasTime ? (last.time - first.time) / 1000 : Infinity;

            // Odstranime segment pokud je kratsi nez minDistM NEBO kratsi nez minTimeS
            // Pouzivame OR misto AND — krátky segment je podezrely i kdyz tam auto
            // stalo dlouho na semaforu (velky cas ale mala vzdalenost)
            if (dist < minDistM || dt < minTimeS) {
                seg.indices.forEach(i => removeSet.add(i));
            }
        }

        const kept = [];
        const removed = [];
        for (let i = 0; i < points.length; i++) {
            if (points[i] === null) {
                kept.push(null);
            } else if (removeSet.has(i)) {
                removed.push(points[i]);
            } else {
                kept.push(points[i]);
            }
        }

        return { kept, removed };
    }

    /* ===== Filtr 6: Douglas-Peucker ===== */

    function simplifyDouglasPeucker(points, toleranceM) {
        // Pracujeme po segmentech
        const segments = [];
        let current = [];
        let currentIndices = [];

        for (let i = 0; i < points.length; i++) {
            if (points[i] === null) {
                if (current.length > 0) {
                    segments.push({ points: current, indices: currentIndices });
                    current = [];
                    currentIndices = [];
                }
            } else {
                current.push(points[i]);
                currentIndices.push(i);
            }
        }
        if (current.length > 0) {
            segments.push({ points: current, indices: currentIndices });
        }

        const keepSet = new Set();

        for (const seg of segments) {
            const keepIndices = dpRecurse(seg.points, 0, seg.points.length - 1, toleranceM);
            for (const ki of keepIndices) {
                keepSet.add(seg.indices[ki]);
            }
        }

        // Pridame i null (segment boundaries)
        const kept = [];
        const removed = [];
        for (let i = 0; i < points.length; i++) {
            if (points[i] === null) {
                kept.push(null);
            } else if (keepSet.has(i)) {
                kept.push(points[i]);
            } else {
                removed.push(points[i]);
            }
        }

        return { kept, removed };
    }

    function dpRecurse(pts, start, end, tol) {
        if (start >= end) return [start];

        let maxDist = 0;
        let maxIdx = start;

        for (let i = start + 1; i < end; i++) {
            const d = crossTrackDistance(pts[i], pts[start], pts[end]);
            if (d > maxDist) {
                maxDist = d;
                maxIdx = i;
            }
        }

        if (maxDist > tol) {
            const left = dpRecurse(pts, start, maxIdx, tol);
            const right = dpRecurse(pts, maxIdx, end, tol);
            // Merge bez duplicit
            return [...left, ...right.slice(1)];
        } else {
            return [start, end];
        }
    }

    /* ===== Detekce mezer po filtraci ===== */

    /**
     * Po odstraneni bodu (napr. jizda autem) mohou zbytle body byt daleko od sebe.
     * Tato funkce vlozi null (segment break) kdyz dva po sobe jdouci body jsou
     * dale nez gapDistM metru nebo casovy rozdil je vetsi nez gapTimeS sekund.
     */
    function detectGaps(points, gapDistM, gapTimeS) {
        const result = [];
        let prevReal = null;

        for (let i = 0; i < points.length; i++) {
            if (points[i] === null) {
                result.push(null);
                prevReal = null;
                continue;
            }

            if (prevReal) {
                const dist = haversine(prevReal.lat, prevReal.lon, points[i].lat, points[i].lon);
                const hasTime = prevReal.time !== null && points[i].time !== null;
                const dt = hasTime ? (points[i].time - prevReal.time) / 1000 : 0;

                // Vloz segment break pokud je velka mezera
                if (dist > gapDistM || (hasTime && dt > gapTimeS)) {
                    result.push(null); // segment boundary
                }
            }

            result.push(points[i]);
            prevReal = points[i];
        }

        return result;
    }

    /* ===== Pipeline ===== */

    function applyAll(points, params) {
        const removedByFilter = {
            speed: [],
            spike: [],
            stationary: [],
            elevation: [],
            segment: [],
            simplify: []
        };
        const warnings = [];

        let current = points;

        // 1. Max rychlost
        if (params.speed_enabled) {
            const r = filterMaxSpeed(current, params.max_speed_kmh, params.speed_window);
            current = r.kept;
            removedByFilter.speed = r.removed;
            if (r.warning === "no_time") warnings.push("Trasa neobsahuje casove znacky — filtr rychlosti preskocen.");
        }

        // 1b. Detekce mezer po filtru rychlosti
        if (removedByFilter.speed.length > 0) {
            current = detectGaps(current, 200, 60);
        }

        // 2. GPS skoky
        if (params.spike_enabled) {
            const r = filterSpikes(current, params.spike_distance_m, params.spike_time_s);
            current = r.kept;
            removedByFilter.spike = r.removed;
        }

        // 3. Stani na miste
        if (params.stationary_enabled) {
            const r = filterStationary(current, params.stationary_radius_m, params.stationary_min_time_s);
            current = r.kept;
            removedByFilter.stationary = r.removed;
        }

        // 4. Vyskove anomalie
        if (params.elevation_enabled) {
            const r = filterElevationSpikes(current, params.elevation_spike_m);
            current = r.kept;
            removedByFilter.elevation = r.removed;
        }

        // 4b. Druha detekce mezer — stationary/spike/elevation mohly
        // vytvorit nove mezery, ktere je treba detekovat PRED
        // filtrem kratkych segmentu
        current = detectGaps(current, 200, 60);

        // 5. Kratke segmenty — nyni vidi spravne segmenty
        if (params.segment_enabled) {
            const r = filterShortSegments(current, params.min_segment_distance_m, params.min_segment_time_s);
            current = r.kept;
            removedByFilter.segment = r.removed;
        }

        // 6. Douglas-Peucker
        if (params.simplify_enabled) {
            const r = simplifyDouglasPeucker(current, params.simplify_tolerance_m);
            current = r.kept;
            removedByFilter.simplify = r.removed;
        }

        // Finalni detekce mezer (po vsech filtrech)
        current = detectGaps(current, 200, 60);

        const origStats = calcStats(points);
        const filteredStats = calcStats(current);

        return {
            filtered: current,
            removedByFilter,
            origStats,
            filteredStats,
            warnings
        };
    }

    /* ===== Statistiky ===== */

    function calcStats(points) {
        const real = points.filter(p => p !== null);
        if (real.length === 0) {
            return { pointCount: 0, distanceKm: 0, durationS: 0, ascentM: 0, descentM: 0, eleMin: 0, eleMax: 0 };
        }

        let dist = 0;
        let ascent = 0;
        let descent = 0;
        let eleMin = Infinity;
        let eleMax = -Infinity;

        // Pocitame jen uvnitr segmentu (ne pres null boundary)
        let prevReal = null;
        for (let i = 0; i < points.length; i++) {
            if (points[i] === null) {
                prevReal = null;
                continue;
            }
            const p = points[i];
            if (p.ele !== null) {
                if (p.ele < eleMin) eleMin = p.ele;
                if (p.ele > eleMax) eleMax = p.ele;
            }
            if (prevReal) {
                dist += haversine(prevReal.lat, prevReal.lon, p.lat, p.lon);
                if (prevReal.ele !== null && p.ele !== null) {
                    const diff = p.ele - prevReal.ele;
                    if (diff > 0) ascent += diff;
                    else descent += Math.abs(diff);
                }
            }
            prevReal = p;
        }

        const first = real[0];
        const last = real[real.length - 1];
        const dur = (first.time !== null && last.time !== null)
            ? (last.time - first.time) / 1000 : 0;

        return {
            pointCount: real.length,
            distanceKm: dist / 1000,
            durationS: dur,
            ascentM: ascent,
            descentM: descent,
            eleMin: eleMin === Infinity ? 0 : eleMin,
            eleMax: eleMax === -Infinity ? 0 : eleMax
        };
    }

    /* ===== Export GPX ===== */

    function exportGpx(originalXmlText, filteredPoints) {
        const parser = new DOMParser();
        const xml = parser.parseFromString(originalXmlText, "application/xml");

        // Puvodni trkpt uzly v poradi dokumentu — origIndex ukazuje sem.
        const allTrkPt = Array.from(xml.getElementsByTagName("trkpt"));

        // Rozdel zachovane body na souvisle useky podle null (mezer/zlomu).
        // Kazdy usek se stane samostatnym <trkseg>, aby se vzdalenost nepocitala
        // pres diru (napr. po odfiltrovani jizdy autem mezi dvema vyslapy).
        const segIndices = [];
        let cur = [];
        for (const p of filteredPoints) {
            if (p === null) {
                if (cur.length) { segIndices.push(cur); cur = []; }
            } else {
                cur.push(p.origIndex);
            }
        }
        if (cur.length) segIndices.push(cur);

        const trk = xml.getElementsByTagName("trk")[0];
        if (trk && segIndices.length > 0) {
            const nsUri = trk.namespaceURI;
            // Odstran vsechny puvodni <trkseg> (uzly <trkpt> si drzime v allTrkPt).
            Array.from(xml.getElementsByTagName("trkseg")).forEach(s => s.parentNode.removeChild(s));
            // Pro kazdy souvisly usek vytvor novy <trkseg> a presun do nej puvodni body.
            for (const idxList of segIndices) {
                const seg = nsUri ? xml.createElementNS(nsUri, "trkseg") : xml.createElement("trkseg");
                for (const origIdx of idxList) {
                    const node = allTrkPt[origIdx];
                    if (node) seg.appendChild(node);
                }
                trk.appendChild(seg);
            }
        } else {
            // Fallback (zadny <trk> nebo zadne body) — puvodni chovani.
            const keepSet = new Set();
            for (const p of filteredPoints) { if (p !== null) keepSet.add(p.origIndex); }
            for (let i = 0; i < allTrkPt.length; i++) {
                if (!keepSet.has(i)) allTrkPt[i].parentNode.removeChild(allTrkPt[i]);
            }
            Array.from(xml.getElementsByTagName("trkseg")).forEach(seg => {
                if (seg.getElementsByTagName("trkpt").length === 0) seg.parentNode.removeChild(seg);
            });
        }

        const serializer = new XMLSerializer();
        return '<?xml version="1.0" encoding="UTF-8"?>\n' + serializer.serializeToString(xml.documentElement);
    }

    /* ===== Vychozi parametry pro presety ===== */

    const PRESETS = {
        hiking: {
            name: "Turistika (standard)",
            builtin: true,
            settings: {
                speed_enabled: true, max_speed_kmh: 12, speed_window: 3,
                spike_enabled: true, spike_distance_m: 100, spike_time_s: 10,
                stationary_enabled: true, stationary_radius_m: 15, stationary_min_time_s: 300,
                elevation_enabled: true, elevation_spike_m: 30,
                segment_enabled: true, min_segment_distance_m: 200, min_segment_time_s: 120,
                simplify_enabled: false, simplify_tolerance_m: 5
            }
        },
        trail: {
            name: "Trail running",
            builtin: true,
            settings: {
                speed_enabled: true, max_speed_kmh: 20, speed_window: 3,
                spike_enabled: true, spike_distance_m: 100, spike_time_s: 10,
                stationary_enabled: true, stationary_radius_m: 15, stationary_min_time_s: 120,
                elevation_enabled: true, elevation_spike_m: 30,
                segment_enabled: true, min_segment_distance_m: 200, min_segment_time_s: 120,
                simplify_enabled: false, simplify_tolerance_m: 5
            }
        },
        walk: {
            name: "Prochazka",
            builtin: true,
            settings: {
                speed_enabled: true, max_speed_kmh: 8, speed_window: 3,
                spike_enabled: true, spike_distance_m: 80, spike_time_s: 10,
                stationary_enabled: true, stationary_radius_m: 10, stationary_min_time_s: 300,
                elevation_enabled: true, elevation_spike_m: 20,
                segment_enabled: true, min_segment_distance_m: 150, min_segment_time_s: 120,
                simplify_enabled: false, simplify_tolerance_m: 5
            }
        },
        cycling: {
            name: "Cyklistika",
            builtin: true,
            settings: {
                speed_enabled: true, max_speed_kmh: 45, speed_window: 3,
                spike_enabled: true, spike_distance_m: 150, spike_time_s: 10,
                stationary_enabled: true, stationary_radius_m: 20, stationary_min_time_s: 180,
                elevation_enabled: true, elevation_spike_m: 50,
                segment_enabled: true, min_segment_distance_m: 300, min_segment_time_s: 120,
                simplify_enabled: false, simplify_tolerance_m: 5
            }
        }
    };

    /* ===== Public API ===== */

    return {
        parseGpx,
        haversine,
        calcSpeeds,
        filterMaxSpeed,
        filterSpikes,
        filterStationary,
        filterElevationSpikes,
        filterShortSegments,
        simplifyDouglasPeucker,
        applyAll,
        calcStats,
        exportGpx,
        PRESETS
    };

})();
