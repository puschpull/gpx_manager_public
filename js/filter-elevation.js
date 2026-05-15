/**
 * ===========================================================
 *  GPX Track Cleaner – Elevation Profile Module
 *  Dvojity vyskovy profil: original (sedy) + filtrovany (barevny)
 * ===========================================================
 */

console.log("filter-elevation.js nacten");

window.FilterElevation = (function () {
    "use strict";

    let chart = null;
    let sampledFiltered = { distKm: [], elevM: [], latArr: [], lonArr: [] };

    function subsample(distKm, elevM, latArr, lonArr, maxPoints) {
        maxPoints = maxPoints || 2000;
        const n = distKm.length;
        if (n <= maxPoints) return { distKm, elevM, latArr, lonArr };
        const step = Math.ceil(n / maxPoints);
        const d2 = [], e2 = [], la2 = [], lo2 = [];
        for (let i = 0; i < n; i += step) {
            d2.push(distKm[i]);
            e2.push(elevM[i]);
            la2.push(latArr[i]);
            lo2.push(lonArr[i]);
        }
        if (d2[d2.length - 1] !== distKm[n - 1]) {
            d2.push(distKm[n - 1]);
            e2.push(elevM[n - 1]);
            la2.push(latArr[n - 1]);
            lo2.push(lonArr[n - 1]);
        }
        return { distKm: d2, elevM: e2, latArr: la2, lonArr: lo2 };
    }

    function pointsToArrays(points) {
        const distKm = [], elevM = [], latArr = [], lonArr = [];
        let cumDist = 0;
        let prevP = null;

        for (const p of points) {
            if (p === null) {
                prevP = null;
                continue;
            }
            if (prevP) {
                cumDist += GpxFilter.haversine(prevP.lat, prevP.lon, p.lat, p.lon);
            }
            distKm.push(cumDist / 1000);
            elevM.push(p.ele !== null ? p.ele : 0);
            latArr.push(p.lat);
            lonArr.push(p.lon);
            prevP = p;
        }

        return { distKm, elevM, latArr, lonArr };
    }

    function update(originalPoints, filteredPoints) {
        const origArrays = pointsToArrays(originalPoints);
        const filtArrays = pointsToArrays(filteredPoints);

        const origSampled = subsample(origArrays.distKm, origArrays.elevM, origArrays.latArr, origArrays.lonArr);
        sampledFiltered = subsample(filtArrays.distKm, filtArrays.elevM, filtArrays.latArr, filtArrays.lonArr);

        const origData = origSampled.distKm.map((x, i) => ({ x, y: origSampled.elevM[i] }));
        const filtData = sampledFiltered.distKm.map((x, i) => ({ x, y: sampledFiltered.elevM[i] }));

        render(origData, filtData);
        populateFilterElevDataTable(sampledFiltered);
    }

    /* A11Y-022: Populate hidden data table for screen readers */
    function populateFilterElevDataTable(s) {
        const tbody = document.getElementById("filterElev-data-tbody");
        if (!tbody) return;

        // Subsample to ~50 points
        const maxRows = 50;
        const sampled = subsample(s.distKm, s.elevM, s.latArr, s.lonArr, maxRows);

        tbody.innerHTML = "";
        for (let i = 0; i < sampled.distKm.length; i++) {
            const tr = document.createElement("tr");
            const tdDist = document.createElement("td");
            const tdElev = document.createElement("td");
            tdDist.textContent = sampled.distKm[i].toFixed(3);
            tdElev.textContent = sampled.elevM[i].toFixed(1);
            tr.appendChild(tdDist);
            tr.appendChild(tdElev);
            tbody.appendChild(tr);
        }
    }

    function render(origData, filtData) {
        const canvas = document.getElementById("filterElev");
        if (!canvas) return;
        const ctx = canvas.getContext("2d");
        if (chart) chart.destroy();

        chart = new Chart(ctx, {
            type: "line",
            data: {
                datasets: [
                    {
                        label: "Original",
                        data: origData,
                        fill: false,
                        tension: 0.1,
                        borderWidth: 1,
                        borderColor: "rgba(150,150,150,0.5)",
                        pointRadius: 0,
                        order: 2
                    },
                    {
                        label: "Vycisteny",
                        data: filtData,
                        fill: false,
                        tension: 0.15,
                        borderWidth: 2,
                        borderColor: "#2196F3",
                        pointRadius: 0,
                        order: 1
                    }
                ]
            },
            options: {
                parsing: false,
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                scales: {
                    x: {
                        type: "linear",
                        title: { display: true, text: "Vzdalenost (km)", font: { size: 11 } }
                    },
                    y: {
                        title: { display: true, text: "Vyska (m)", font: { size: 11 } }
                    }
                },
                plugins: {
                    legend: {
                        display: true,
                        position: "top",
                        labels: { font: { size: 11 }, boxWidth: 20, padding: 10 }
                    },
                    tooltip: {
                        intersect: false,
                        mode: "nearest",
                        filter: item => item.datasetIndex === 1,
                        callbacks: {
                            label: ctx => ` ${ctx.parsed.y.toFixed(1)} m @ ${ctx.parsed.x.toFixed(3)} km`
                        }
                    }
                },
                interaction: { intersect: false, mode: "nearest" }
            }
        });

        // Hover -> mapa marker
        canvas.addEventListener("mousemove", evt => {
            if (!chart) return;
            const elems = chart.getElementsAtEventForMode(evt, "nearest", { intersect: false }, false);
            if (elems?.length) {
                const ds = elems[0].datasetIndex;
                const idx = elems[0].index;
                if (ds === 1 && sampledFiltered.latArr[idx] !== undefined) {
                    FilterMap.moveHoverMarker(sampledFiltered.latArr[idx], sampledFiltered.lonArr[idx]);
                }
            }
        });

        canvas.addEventListener("mouseleave", () => FilterMap.hideHoverMarker());

        canvas.addEventListener("click", evt => {
            if (!chart) return;
            const m = FilterMap.getMap();
            if (!m) return;
            const elems = chart.getElementsAtEventForMode(evt, "nearest", { intersect: false }, false);
            if (elems?.length) {
                const idx = elems[0].index;
                if (elems[0].datasetIndex === 1) {
                    const lat = sampledFiltered.latArr[idx];
                    const lon = sampledFiltered.lonArr[idx];
                    if (lat !== undefined && lon !== undefined) {
                        FilterMap.moveHoverMarker(lat, lon);
                        m.flyTo([lat, lon], Math.max(m.getZoom(), 16), { duration: 0.8 });
                    }
                }
            }
        });
    }

    return { update };

})();
