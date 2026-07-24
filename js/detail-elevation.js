/**
 * ===========================================================
 *  GPX Manager – Detail Elevation Module (v3.6)
 *  Barevné segmenty + legenda + přepínatelný barevný tooltip
 * ===========================================================
 */

if (window.GPX_DEBUG) console.log("📈 detail-elevation.js načten");

let chart;
let sampled = { distKm: [], elevM: [], latArr: [], lonArr: [], timesArr: [] };

/* ===== i18n helper ===== */
const _ei18n = (window.gpxDetailData && window.gpxDetailData.i18n) || {};
const ei = key => _ei18n[key] || key;

/* ===== Pomocné funkce — delegáty na sdílené lib (js/lib/geo-utils.js) ===== */
function haversine(lat1, lon1, lat2, lon2) { return window.GpxGeo.haversine(lat1, lon1, lat2, lon2); }
function subsampleArrays(distKm, elevM, latArr, lonArr, timesArr, maxPoints) {
    return window.GpxGeo.subsampleArrays(distKm, elevM, latArr, lonArr, timesArr, maxPoints || 2000);
}

/* ===== Hlavní logika ===== */
document.addEventListener("gpxDataReady", e => {
    if (window.GPX_DEBUG) console.log("📊 Zpracovávám GPX data pro výškový profil…");

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
    populateElevDataTable();

    if (window.GPX_DEBUG) console.log(`✅ Výškový graf vykreslen (${dataXY.length} bodů)`);

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

/* ===== Styl legendy a přepínače =====
 * Pravidla přesunuta do css/detail.css (FE-12 / TASK-24).
 * Stylesheet musí být načten v <head> stránky, která používá tento modul.
 * Viz includes/detail_view.php: <link rel="stylesheet" href="css/detail.css">
 * ===== */

/* ===== A11Y-022: Populate hidden data table for screen readers ===== */
function populateElevDataTable() {
    const tbody = document.getElementById("elev-data-tbody");
    if (!tbody) return;

    // Subsample to ~50 points for a manageable table
    const maxRows = 50;
    const sub = window.GpxGeo.subsampleArrays(
        sampled.distKm, sampled.elevM,
        sampled.latArr, sampled.lonArr,
        sampled.timesArr, maxRows
    );

    tbody.innerHTML = "";
    for (let i = 0; i < sub.distKm.length; i++) {
        const tr = document.createElement("tr");
        const tdDist = document.createElement("td");
        const tdElev = document.createElement("td");
        tdDist.textContent = sub.distKm[i].toFixed(3);
        tdElev.textContent = sub.elevM[i].toFixed(1);
        tr.appendChild(tdDist);
        tr.appendChild(tdElev);
        tbody.appendChild(tr);
    }
}
