/**
 * GPX Manager — year summary page (year.php): map of the year's tracks,
 * monthly chart (this year vs previous) and the year selector.
 */
(function () {
    "use strict";

    document.addEventListener("DOMContentLoaded", function () {
        var D = window.gpxYearData;

        var sel = document.getElementById("yrSelect");
        if (sel) sel.addEventListener("change", function () { sel.form.submit(); });

        if (!D) return;

        if (window.GpxTrackSetMap) {
            window.GpxTrackSetMap.create("yrMap", { linesUrl: D.linesUrl, apiKeys: D.apiKeys, i18n: D.i18n });
        }

        var canvas = document.getElementById("yrMonthChart");
        if (canvas && window.Chart) {
            var P = window.OutdoorChartTheme || { forest700: "#2d4a3e", forest500: "#3f6b57", terracotta500: "#c97b3f", textMuted: "#5b7568", gridSoft: "rgba(45,74,62,.08)" };
            new Chart(canvas.getContext("2d"), {
                data: {
                    labels: D.months,
                    datasets: [
                        { type: "bar", label: D.i18n.km, data: D.km, backgroundColor: P.forest500 + "CC",
                          borderColor: P.forest700, borderWidth: 1, borderRadius: 4, order: 2 },
                        { type: "line", label: D.i18n.kmPrev, data: D.kmPrev, borderColor: P.terracotta500,
                          backgroundColor: P.terracotta500, borderDash: [5, 4], tension: 0.3, pointRadius: 2, order: 1 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    animation: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? false : {},
                    plugins: { legend: { position: "bottom", labels: { color: P.textMuted } } },
                    scales: {
                        x: { ticks: { color: P.textMuted }, grid: { color: P.gridSoft } },
                        y: { ticks: { color: P.textMuted }, grid: { color: P.gridSoft }, beginAtZero: true }
                    }
                }
            });
        }
    });
})();
