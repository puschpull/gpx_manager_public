/**
 * ===========================================================
 *  GPX Manager – Detail Elevation Module (v3.6)
 *  Barevné segmenty + legenda + přepínatelný barevný tooltip
 * ===========================================================
 */

console.log("📈 detail-elevation.js načten");

let chart;
let sampled = { distKm: [], elevM: [], latArr: [], lonArr: [], timesArr: [] };

/* ===== i18n helper ===== */
const _ei18n = (window.gpxDetailData && window.gpxDetailData.i18n) || {};
const ei = key => _ei18n[key] || key;

/* ===== Pomocné funkce ===== */
function toRad(d) { return d * Math.PI / 180; }
function haversine(lat1, lon1, lat2, lon2) {
    const R = 6371000;
    const dLat = toRad(lat2 - lat1);
    const dLon = toRad(lon2 - lon1);
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon / 2) ** 2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}
function subsampleArrays(distKm, elevM, latArr, lonArr, timesArr, maxPoints = 2000) {
    const n = distKm.length;
    if (n <= maxPoints) return { distKm, elevM, latArr, lonArr, timesArr };
    const step = Math.ceil(n / maxPoints);
    const d2 = [], e2 = [], la2 = [], lo2 = [], t2 = [];
    for (let i = 0; i < n; i += step) {
        d2.push(distKm[i]);
        e2.push(elevM[i]);
        la2.push(latArr[i]);
        lo2.push(lonArr[i]);
        t2.push(timesArr[i]);
    }
    if (d2[d2.length - 1] !== distKm[n - 1]) {
        d2.push(distKm[n - 1]);
        e2.push(elevM[n - 1]);
        la2.push(latArr[n - 1]);
        lo2.push(lonArr[n - 1]);
        t2.push(timesArr[n - 1]);
    }
    return { distKm: d2, elevM: e2, latArr: la2, lonArr: lo2, timesArr: t2 };
}

/* ===== Hlavní logika ===== */
document.addEventListener("gpxDataReady", e => {
    console.log("📊 Zpracovávám GPX data pro výškový profil…");

    const xmlText = e.detail.xmlText;
    const parser = new DOMParser();
    const xml = parser.parseFromString(xmlText, "application/xml");
    const pts = Array.from(xml.getElementsByTagName("trkpt"));
    if (!pts.length) {
        console.warn("⚠️ Žádné <trkpt> body – nelze vykreslit profil.");
        return;
    }

    const distKmFull = [], elevMFull = [], latFull = [], lonFull = [], timesFull = [];
    let cumDist = 0;

    let prevLat = parseFloat(pts[0].getAttribute("lat"));
    let prevLon = parseFloat(pts[0].getAttribute("lon"));
    let prevEle = parseFloat((pts[0].getElementsByTagName("ele")[0] || {}).textContent || "0");
    distKmFull.push(0);
    elevMFull.push(prevEle);
    latFull.push(prevLat);
    lonFull.push(prevLon);
    timesFull.push((pts[0].getElementsByTagName("time")[0] || {}).textContent || "");

    for (let i = 1; i < pts.length; i++) {
        const lat = parseFloat(pts[i].getAttribute("lat"));
        const lon = parseFloat(pts[i].getAttribute("lon"));
        const ele = parseFloat((pts[i].getElementsByTagName("ele")[0] || {}).textContent || "0");
        const tIso = (pts[i].getElementsByTagName("time")[0] || {}).textContent || "";

        const d = haversine(prevLat, prevLon, lat, lon);
        cumDist += d;

        distKmFull.push(cumDist / 1000);
        elevMFull.push(ele);
        latFull.push(lat);
        lonFull.push(lon);
        timesFull.push(tIso);

        prevLat = lat;
        prevLon = lon;
        prevEle = ele;
    }

    sampled = subsampleArrays(distKmFull, elevMFull, latFull, lonFull, timesFull, 2000);
    const dataXY = sampled.distKm.map((x, i) => ({ x, y: sampled.elevM[i] }));

    renderElevation(dataXY);
    createSlopeLegend();
    createTooltipToggle();

    console.log(`✅ Výškový graf vykreslen (${dataXY.length} bodů)`);

    const canvas = document.getElementById("elev");
    if (!canvas) return;

    canvas.addEventListener("mousemove", evt => {
        if (!chart) return;
        const elems = chart.getElementsAtEventForMode(evt, "nearest", { intersect: false }, false);
        if (elems?.length) {
            const idx = elems[0].index;
            moveHoverMarker(sampled.latArr[idx], sampled.lonArr[idx]);
        }
    });

    canvas.addEventListener("mouseleave", () => hideHoverMarker());

    canvas.addEventListener("click", evt => {
        if (!chart || typeof map === "undefined") return;
        const elems = chart.getElementsAtEventForMode(evt, "nearest", { intersect: false }, false);
        if (elems?.length) {
            const idx = elems[0].index;
            const lat = sampled.latArr[idx];
            const lon = sampled.lonArr[idx];
            if (lat !== undefined && lon !== undefined) {
                moveHoverMarker(lat, lon);
            //  map.flyTo([lat, lon], Math.max(map.getZoom(), 14), { duration: 0.6 });
			//	map.flyTo([lat, lon], 15, { duration: 0.8 });
				map.flyTo([lat, lon], Math.max(map.getZoom(), 16), { duration: 0.8 });
            }
        }
    });
});

/* ===== Vykreslení grafu ===== */
function renderElevation(dataXY) {
    const canvas = document.getElementById("elev");
    const ctx = canvas.getContext("2d");
    if (chart) chart.destroy();

    const colorfulTooltip = localStorage.getItem("colorfulTooltip") === "true";

    const getSlopeValue = i => {
        if (i <= 0 || i >= sampled.elevM.length) return 0;
        const dh = sampled.elevM[i] - sampled.elevM[i - 1];
        const dx = (sampled.distKm[i] - sampled.distKm[i - 1]) * 1000;
        return dx > 0 ? (dh / dx) * 100 : 0;
    };

    const getSlopeColor = i => {
        const slope = getSlopeValue(i);
        if (slope < -5) return "#009900";
        if (slope < -1) return "#55aa55";
        if (slope < 2)  return "#ffaa00";
        return "#ff3300";
    };

    chart = new Chart(ctx, {
        type: "line",
        data: {
            datasets: [{
                label: ei('elev_label_elev'),
                data: dataXY,
                fill: false,
                tension: 0.15,
                borderWidth: 2,
                pointRadius: 0,
                segment: { borderColor: ctx => getSlopeColor(ctx.p1DataIndex) }
            }]
        },
        options: {
            parsing: false,
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            scales: {
                x: { type: "linear", title: { display: true, text: ei('elev_label_dist') } },
                y: { title: { display: true, text: ei('elev_label_elev') } }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    intersect: false,
                    mode: "nearest",
                    backgroundColor: context => {
                        if (!colorfulTooltip) return "rgba(40,40,40,0.9)";
                        const dp = context?.tooltip?.dataPoints?.[0];
                        if (!dp) return "rgba(60,60,60,0.9)";
                        const slope = getSlopeValue(dp.dataIndex);
                        if (slope < -1) return "rgba(0,180,0,0.85)";
                        if (slope < 2)  return "rgba(255,200,0,0.85)";
                        return "rgba(255,70,50,0.9)";
                    },
                    borderColor: context => {
                        if (!colorfulTooltip) return "rgba(90,90,90,1)";
                        const dp = context?.tooltip?.dataPoints?.[0];
                        if (!dp) return "rgba(60,60,60,1)";
                        const slope = getSlopeValue(dp.dataIndex);
                        if (slope < -1) return "rgba(0,140,0,1)";
                        if (slope < 2)  return "rgba(210,165,0,1)";
                        return "rgba(210,55,40,1)";
                    },
                    borderWidth: 1.5,
                    callbacks: {
                        label: ctx => {
                            const idx = ctx.dataIndex;
                            const iso = sampled.timesArr[idx];
                            const ts = iso ? fmtDateTime(iso, window.timeMode) : "";
                            const slope = getSlopeValue(idx);
                            const slopeStr = slope > 0 ? `📈 +${slope.toFixed(1)}%`
                                : slope < 0 ? `📉 ${slope.toFixed(1)}%`
                                    : "➡️ 0.0%";
                            return ` ${ctx.parsed.y.toFixed(1)} m @ ${ctx.parsed.x.toFixed(3)} km, ${slopeStr}${ts ? " – " + ts : ""}`;
                        }
                    }
                }
            },
            interaction: { intersect: false, mode: "nearest" }
        }
    });

    window.chart = chart;
}

/* ===== Legenda + přepínač ===== */
function createSlopeLegend() {
    const wrap = document.getElementById("elev-wrap");
    if (!wrap) return;
    const old = document.getElementById("slope-legend");
    if (old) old.remove();

    const legend = document.createElement("div");
    legend.id = "slope-legend";
    legend.className = "slope-legend";
    legend.innerHTML = `
    <div class="legend-item"><span style="background:#009900"></span> ${ei('slope_steep_down')} (&lt; −5%)</div>
    <div class="legend-item"><span style="background:#55aa55"></span> ${ei('slope_mild_down')} (−1% … −5%)</div>
    <div class="legend-item"><span style="background:#ffaa00"></span> ${ei('slope_flat')} (−1% … +2%)</div>
    <div class="legend-item"><span style="background:#ff3300"></span> ${ei('slope_steep_up')} (&gt; +2%)</div>
  `;
    wrap.appendChild(legend);
}

function createTooltipToggle() {
    const wrap = document.getElementById("elev-wrap");
    if (!wrap) return;
    const toggle = document.createElement("label");
    toggle.className = "tooltip-toggle";
    const checked = localStorage.getItem("colorfulTooltip") === "true";
    toggle.innerHTML = `
    <input type="checkbox" id="colorfulTooltipToggle" ${checked ? "checked" : ""}>
    <span>${ei('colorful_tooltip')}</span>
  `;
    wrap.appendChild(toggle);

    toggle.querySelector("input").addEventListener("change", e => {
        localStorage.setItem("colorfulTooltip", e.target.checked);
        renderElevation(sampled.distKm.map((x, i) => ({ x, y: sampled.elevM[i] })));
    });
}

/* ===== Styl legendy a přepínače ===== */
const style = document.createElement("style");
style.textContent = `
  .slope-legend {
    margin-top: 12px;
    display: flex;
    flex-wrap: wrap;
    gap: 10px 20px;
    font-size: 0.9em;
    opacity: 0.9;
  }
  .slope-legend .legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .slope-legend .legend-item span {
    display: inline-block;
    width: 16px;
    height: 10px;
    border-radius: 2px;
    box-shadow: 0 0 2px rgba(0,0,0,0.3);
  }
  .tooltip-toggle {
    margin-top: 10px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.9em;
    opacity: 0.85;
    cursor: pointer;
  }
  .tooltip-toggle input {
    transform: scale(1.2);
  }
`;
document.head.appendChild(style);
